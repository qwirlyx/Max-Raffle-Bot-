<?php
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/max_api.php';
require_once __DIR__ . '/lib/telegram_api.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/broadcasts.php';

$lockFile = __DIR__ . '/data/broadcast_cron.lock';
if (!file_exists(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0777, true);
}
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("Broadcast cron is already running.\n");
}

$logFile = __DIR__ . '/data/broadcast_cron.log';
ini_set('display_errors', 1);
error_reporting(E_ALL);

function broadcast_extract_message_id($res) {
    if (isset($res['message']['body']['mid'])) return $res['message']['body']['mid'];
    if (isset($res['message']['id'])) return $res['message']['id'];
    if (isset($res['message_id'])) return $res['message_id'];
    if (isset($res['data']['message_id'])) return $res['data']['message_id'];
    if (isset($res['result']['message_id'])) return $res['result']['message_id'];
    if (isset($res['id'])) return $res['id'];
    return null;
}

function broadcast_platform_from_row(array $broadcast): string {
    return (($broadcast['platform'] ?? 'max') === 'telegram') ? 'telegram' : 'max';
}

function broadcast_telegram_error_text($res): ?string {
    if (!is_array($res)) {
        return 'Telegram API returned non-array/null response';
    }
    if (!empty($res['ok'])) {
        return null;
    }
    return $res['description'] ?? $res['message'] ?? 'Telegram send failed';
}


function broadcast_telegram_photo_should_fallback(?string $errorText): bool {
    $errorText = strtolower((string)$errorText);
    if ($errorText === '') {
        return false;
    }

    $needles = [
        'photo_invalid_dimensions',
        'wrong type of the web page content',
        'failed to get http url content',
        'wrong file identifier',
        'invalid file http url',
    ];

    foreach ($needles as $needle) {
        if (strpos($errorText, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function broadcast_telegram_media_source(array $broadcast): string {
    $mediaUrl = trim((string)($broadcast['media_url'] ?? ''));
    $payloadRaw = (string)($broadcast['media_payload'] ?? '');

    if ($payloadRaw !== '') {
        $payload = json_decode($payloadRaw, true);
        if (is_array($payload) && !empty($payload['local_path']) && is_file($payload['local_path'])) {
            return $payload['local_path'];
        }
    }

    $localPath = project_media_url_to_local_path($mediaUrl, PROJECT_URL);
    if ($localPath !== null) {
        return $localPath;
    }

    return $mediaUrl;
}

function broadcast_send_telegram(array $broadcast, $userId): array {
    $text = (string)($broadcast['message_text'] ?? '');
    $mediaUrl = trim((string)($broadcast['media_url'] ?? ''));
    $mediaType = (string)($broadcast['media_type'] ?? '');

    if ($mediaUrl === '') {
        return telegram_send_message($userId, $text);
    }

    // В Telegram подпись к медиа ограничена. Если текст длинный, сначала отправляем текст, затем файл.
    if (mb_strlen($text, 'UTF-8') > 900) {
        $textRes = telegram_send_message($userId, $text);
        $textError = broadcast_telegram_error_text($textRes);
        if ($textError !== null) {
            return $textRes;
        }
        $text = '';
    }

    $mediaSource = broadcast_telegram_media_source($broadcast);

    if ($mediaType === 'image') {
        $photoRes = telegram_send_photo($userId, $mediaSource, $text);
        $photoError = broadcast_telegram_error_text($photoRes);
        if ($photoError !== null && broadcast_telegram_photo_should_fallback($photoError)) {
            $docRes = telegram_send_document($userId, $mediaSource, $text);
            $docError = broadcast_telegram_error_text($docRes);
            if ($docError === null) {
                return $docRes;
            }
            $docRes['description'] = $photoError . ' | document fallback: ' . $docError;
            $docRes['message'] = $docRes['description'];
            return $docRes;
        }
        return $photoRes;
    }
    if ($mediaType === 'video') {
        return telegram_send_video($userId, $mediaSource, $text);
    }
    return telegram_send_document($userId, $mediaSource, $text);
}

function broadcast_prepare_max_attachments(array $broadcast): ?array {
    if (empty($broadcast['media_type'])) {
        return null;
    }

    $payload = null;
    if (!empty($broadcast['media_payload'])) {
        $decodedPayload = json_decode($broadcast['media_payload'], true);
        if (is_array($decodedPayload) && !empty($decodedPayload)) {
            $payload = $decodedPayload;
        }
    }

    if ($payload !== null) {
        return [[
            'type' => $broadcast['media_type'],
            'payload' => $payload,
        ]];
    }

    if (!empty($broadcast['media_url'])) {
        return [[
            'type' => $broadcast['media_type'],
            'payload' => ['url' => $broadcast['media_url']],
        ]];
    }

    return null;
}

try {
    $pdo = get_db_connection();
    ensure_broadcast_tables($pdo);
    ensure_platform_columns($pdo);

    $stmt = $pdo->query("SELECT * FROM broadcasts WHERE status IN ('pending', 'processing') AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY COALESCE(scheduled_at, created_at) ASC, created_at ASC LIMIT 1");
    $broadcast = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$broadcast) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit("No broadcasts to process.\n");
    }

    $broadcastId = $broadcast['id'];
    $platform = broadcast_platform_from_row($broadcast);
    $batchLimit = ($platform === 'telegram') ? 20 : 30;

    $pdo->prepare("UPDATE broadcasts SET status = 'processing', started_at = COALESCE(started_at, NOW()) WHERE id = ?")->execute([$broadcastId]);

    $stmtRecipients = $pdo->prepare("SELECT * FROM broadcast_recipients WHERE broadcast_id = ? AND status IN ('pending', 'failed') ORDER BY id ASC LIMIT " . (int)$batchLimit);
    $stmtRecipients->execute([$broadcastId]);
    $recipients = $stmtRecipients->fetchAll(PDO::FETCH_ASSOC);

    if (empty($recipients)) {
        $counts = $pdo->prepare("SELECT
            SUM(status = 'pending') AS pending_count,
            SUM(status = 'failed') AS failed_count,
            SUM(status = 'sent') AS sent_count
            FROM broadcast_recipients WHERE broadcast_id = ?");
        $counts->execute([$broadcastId]);
        $stat = $counts->fetch(PDO::FETCH_ASSOC) ?: ['pending_count' => 0, 'failed_count' => 0, 'sent_count' => 0];

        $newStatus = ((int)$stat['failed_count'] > 0) ? 'failed' : 'done';
        $pdo->prepare("UPDATE broadcasts SET status = ?, sent_count = ?, failed_count = ?, finished_at = NOW() WHERE id = ?")
            ->execute([$newStatus, (int)$stat['sent_count'], (int)$stat['failed_count'], $broadcastId]);

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit("Broadcast finished.\n");
    }

    foreach ($recipients as $row) {
        $recipientRowId = $row['id'];
        $userId = $row['user_id'];
        $errorText = null;
        $sendFailed = false;
        $shouldRetryLater = false;
        $res = null;

        if ($platform === 'telegram') {
            $res = broadcast_send_telegram($broadcast, $userId);
            $errorText = broadcast_telegram_error_text($res);
            $sendFailed = $errorText !== null;
        } else {
            $attachments = broadcast_prepare_max_attachments($broadcast);
            $res = max_send_message($userId, $broadcast['message_text'], $attachments, true);

            if ($res === null) {
                $sendFailed = true;
                $errorText = 'MAX API timeout/null';
            } elseif (isset($res['code'])) {
                $sendFailed = true;
                $errorText = $res['message'] ?? $res['code'];
            } elseif (isset($res['success']) && $res['success'] === false) {
                $sendFailed = true;
                $errorText = $res['message'] ?? 'send failed';
            }

            if ($sendFailed && (($res['code'] ?? '') === 'attachment.not.ready' || stripos((string)$errorText, 'errors.process.attachment.file.not.processed') !== false)) {
                $shouldRetryLater = true;
            }
        }

        if ($shouldRetryLater) {
            $pdo->prepare("UPDATE broadcast_recipients SET status = 'pending', error_text = ?, sent_at = NULL WHERE id = ?")
                ->execute([$errorText, $recipientRowId]);
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ⏳ {$platform} broadcast {$broadcastId} attachment not ready for user {$userId}: {$errorText}\n", FILE_APPEND);
            continue;
        }

        if ($sendFailed) {
            $pdo->prepare("UPDATE broadcast_recipients SET status = 'failed', error_text = ?, sent_at = NULL WHERE id = ?")
                ->execute([$errorText, $recipientRowId]);
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ {$platform} broadcast {$broadcastId} user {$userId}: {$errorText}\n", FILE_APPEND);
        } else {
            $pdo->prepare("UPDATE broadcast_recipients SET status = 'sent', error_text = NULL, sent_at = NOW() WHERE id = ?")
                ->execute([$recipientRowId]);

            save_chat_message($userId, null, $broadcast['message_text'], 'out', 'text', null, broadcast_extract_message_id($res), $platform);
            if (!empty($broadcast['media_url'])) {
                $historyType = ($broadcast['media_type'] === 'image') ? 'photo' : 'file';
                save_chat_message($userId, null, $broadcast['media_url'], 'out', $historyType, null, broadcast_extract_message_id($res), $platform);
            }

            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ {$platform} broadcast {$broadcastId} user {$userId}\n", FILE_APPEND);
        }

        if ($platform === 'telegram') {
            usleep(70000);
        }
    }

    $counts = $pdo->prepare("SELECT
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'failed') AS failed_count,
        SUM(status = 'sent') AS sent_count
        FROM broadcast_recipients WHERE broadcast_id = ?");
    $counts->execute([$broadcastId]);
    $stat = $counts->fetch(PDO::FETCH_ASSOC) ?: ['pending_count' => 0, 'failed_count' => 0, 'sent_count' => 0];

    if ((int)$stat['pending_count'] === 0 && (int)$stat['failed_count'] === 0) {
        $status = 'done';
        $finishedAtSql = ', finished_at = NOW()';
    } elseif ((int)$stat['pending_count'] === 0 && (int)$stat['failed_count'] > 0) {
        $status = 'failed';
        $finishedAtSql = ', finished_at = NOW()';
    } else {
        $status = 'processing';
        $finishedAtSql = '';
    }

    $sql = "UPDATE broadcasts SET status = ?, sent_count = ?, failed_count = ? {$finishedAtSql} WHERE id = ?";
    $pdo->prepare($sql)->execute([$status, (int)$stat['sent_count'], (int)$stat['failed_count'], $broadcastId]);
} catch (Throwable $e) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' FATAL: ' . $e->getMessage() . "\n", FILE_APPEND);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
?>
