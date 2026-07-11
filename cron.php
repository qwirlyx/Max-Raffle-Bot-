<?php
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/max_api.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/telegram_api.php';
require_once __DIR__ . '/lib/vk_api.php';

function reserve_promocode(PDO $pdo, $raffleId, $userId, $userName = null) {
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM raffle_promocodes WHERE raffle_id = ? AND status = 'new' ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $stmt->execute([$raffleId]);
        $codeRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$codeRow) {
            $pdo->commit();
            return null;
        }

        $upd = $pdo->prepare("UPDATE raffle_promocodes SET status = 'assigned', assigned_to_user_id = ?, assigned_to_name = ?, assigned_at = NOW() WHERE id = ?");
        $upd->execute([$userId, $userName, $codeRow['id']]);

        $pdo->commit();
        return $codeRow;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function mark_promocode_sent(PDO $pdo, $codeId) {
    $stmt = $pdo->prepare("UPDATE raffle_promocodes SET status = 'sent', sent_at = NOW(), error_text = NULL WHERE id = ?");
    $stmt->execute([$codeId]);
}

function mark_promocode_failed(PDO $pdo, $codeId, $errorText = null) {
    $stmt = $pdo->prepare("UPDATE raffle_promocodes SET status = 'failed', error_text = ? WHERE id = ?");
    $stmt->execute([$errorText, $codeId]);
}

function detect_raffle_media_type(string $filePath): string {
    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    if (strpos($mime, 'image/') === 0) return 'image';
    if (strpos($mime, 'video/') === 0) return 'video';
    if (strpos($mime, 'audio/') === 0) return 'audio';
    return 'file';
}

function build_raffle_media_attachment(?string $mediaUrl): ?array {
    if (!$mediaUrl) {
        return null;
    }

    $baseUrl = rtrim(PROJECT_URL, '/');
    if (strpos($mediaUrl, $baseUrl . '/uploads/') !== 0) {
        return null;
    }

    $relativePath = substr($mediaUrl, strlen($baseUrl));
    $fullPath = __DIR__ . $relativePath;

    if (!is_file($fullPath)) {
        throw new Exception('Локальный файл медиа не найден: ' . $fullPath);
    }

    $mediaType = detect_raffle_media_type($fullPath);
    return max_prepare_attachment_from_file($fullPath, $mediaType);
}

function get_sent_message_id($res) {
    if (isset($res['message']['body']['mid'])) return $res['message']['body']['mid'];
    if (isset($res['message']['id'])) return $res['message']['id'];
    if (isset($res['message_id'])) return $res['message_id'];
    if (isset($res['data']['message_id'])) return $res['data']['message_id'];
    if (isset($res['id'])) return $res['id'];
    return null;
}

function raffle_platform(array $raffle): string {
    return normalize_platform($raffle['platform'] ?? 'max');
}

function telegram_raffle_deep_link(string $payload): string {
    $username = defined('TG_BOT_USERNAME') ? trim((string)TG_BOT_USERNAME) : '';
    if ($username === '') {
        return '';
    }
    $username = ltrim($username, '@');
    return 'https://t.me/' . $username . '?start=' . rawurlencode($payload);
}

function telegram_build_raffle_keyboard(string $raffleId): array {
    $rows = [
        [
            ['text' => 'Участвовать 🎁', 'callback_data' => 'tg_participate_' . $raffleId],
        ],
    ];

    $notifyUrl = telegram_raffle_deep_link('notify_' . $raffleId);
    if ($notifyUrl !== '') {
        $rows[] = [
            ['text' => 'Включить уведомления о выигрыше 🔔', 'url' => $notifyUrl],
        ];
    }

    return telegram_inline_keyboard($rows);
}


function vk_send_raffle_post(array $raffle, string $vkPostFooterTemplate = ''): array {
    $channelId = $raffle['channel_id'] ?: (defined('VK_GROUP_ID') ? 'vk_' . ltrim((string)VK_GROUP_ID, '-') : '');
    $text = trim((string)($raffle['text'] ?? ''));
    $mediaUrl = trim((string)($raffle['media_url'] ?? ''));

    $commentInstruction = '';
    $commentMode = (string)($raffle['vk_comment_mode'] ?? 'word');
    if ($commentMode === 'number') {
        $min = isset($raffle['vk_number_min']) && $raffle['vk_number_min'] !== null && $raffle['vk_number_min'] !== '' ? (int)$raffle['vk_number_min'] : null;
        $max = isset($raffle['vk_number_max']) && $raffle['vk_number_max'] !== null && $raffle['vk_number_max'] !== '' ? (int)$raffle['vk_number_max'] : null;
        $commentInstruction = ($min !== null && $max !== null)
            ? "Для участия напишите в комментариях число от {$min} до {$max}."
            : "Для участия напишите число в комментариях.";
    } elseif (!empty($raffle['vk_require_comment'])) {
        $word = trim((string)($raffle['vk_comment_word'] ?? ''));
        $commentInstruction = $word !== '' ? ("Для участия напишите в комментариях: " . $word) : "Для участия оставьте комментарий под этим постом.";
    }

    $likeInstruction = !empty($raffle['vk_require_like']) ? 'Поставьте лайк на этот пост.' : '';
    $subscriptionInstruction = !empty($raffle['check_subscription']) ? 'Будьте подписаны на сообщество.' : '';
    $botInstruction = !empty($raffle['vk_require_bot_message']) ? 'Напишите любое сообщение в личные сообщения сообщества — так бот сможет отправить промокод победителю.' : '';

    if ($vkPostFooterTemplate === '') {
        $vkPostFooterTemplate = "{comment_instruction}\n{like_instruction}\n{subscription_instruction}\n{bot_instruction}\n\nПобедитель получит промокод в личные сообщения сообщества.";
    }

    $footer = str_replace(
        ['{title}', '{comment_instruction}', '{like_instruction}', '{subscription_instruction}', '{bot_instruction}'],
        [(string)($raffle['title'] ?? ''), $commentInstruction, $likeInstruction, $subscriptionInstruction, $botInstruction],
        $vkPostFooterTemplate
    );
    $footer = preg_replace("~(\n\s*){3,}~", "\n\n", trim((string)$footer));
    if ($footer !== '') $text .= "\n\n" . $footer;

    return vk_wall_post($channelId, $text, $mediaUrl);
}


function raffle_pick_winners(array $pool, int $winnersCount): array {
    $pool = array_values($pool);
    $winnersCount = max(1, $winnersCount);
    if (count($pool) <= $winnersCount) {
        return $pool;
    }

    $picked = [];
    $used = [];
    while (count($picked) < $winnersCount) {
        $idx = random_int(0, count($pool) - 1);
        if (isset($used[$idx])) continue;
        $used[$idx] = true;
        $picked[] = $pool[$idx];
    }
    return $picked;
}

function vk_collect_raffle_participants(PDO $pdo, array $raffle, string $logFile): array {
    $postRef = trim((string)($raffle['post_message_id'] ?? ''));
    if ($postRef === '') return ['added' => 0, 'accepted' => 0, 'checked' => 0, 'skipped' => ['no_post' => 1]];

    // Если wall.getComments доступен — дополнительно подтянем комментарии.
    // Если нет, участники собираются из Callback API события wall_reply_new.
    $commentsRes = vk_get_wall_comments_all($postRef, $raffle['channel_id'] ?? null);
    if (!empty($commentsRes['success'])) {
        $items = $commentsRes['items'] ?? [];
        $ref = vk_parse_wall_ref($postRef, $raffle['channel_id'] ?? null);
        if ($ref) {
            foreach ($items as $comment) {
                $fromId = (int)($comment['from_id'] ?? 0);
                $commentId = (int)($comment['id'] ?? 0);
                if ($fromId > 0 && $commentId > 0) {
                    vk_save_comment_event($pdo, (string)$raffle['id'], (int)$ref['owner_id'], (int)$ref['post_id'], $commentId, $fromId, (string)($comment['text'] ?? ''), isset($comment['date']) ? (int)$comment['date'] : null);
                }
            }
        }
    } else {
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " ⚠️ VK comments API skipped for {$raffle['id']}: " . json_encode($commentsRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    return vk_process_comment_events_for_raffle($pdo, $raffle, $logFile);
}

function finish_vk_raffle(PDO $pdo, array $raffle, string $logFile, string $winnerTemplate, string $winnerPostTemplate, string $nonWinnerTemplate, string $noParticipantsTemplate): void {
    $id = (string)$raffle['id'];
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " 🏁 Finishing VK raffle $id...\n", FILE_APPEND);
    $pdo->prepare("UPDATE raffles SET status = 'processing' WHERE id = ?")->execute([$id]);

    vk_collect_raffle_participants($pdo, $raffle, $logFile);

    $stmtParts = $pdo->prepare("SELECT user_id, name, meta FROM participants WHERE raffle_id = ?");
    $stmtParts->execute([$id]);
    $initialPool = $stmtParts->fetchAll(PDO::FETCH_ASSOC);
    $finalPool = $initialPool;

    $winnersCount = (int)($raffle['winners_count'] ?? 1);
    $winnersList = [];
    $winnersNames = [];

    if (count($finalPool) > 0) {
        $winnersList = raffle_pick_winners($finalPool, $winnersCount);
        foreach ($winnersList as $user) {
            $uid = (string)$user['user_id'];
            $userName = $user['name'] ?? 'Счастливчик';
            $meta = [];
            if (!empty($user['meta'])) {
                $decodedMeta = json_decode((string)$user['meta'], true);
                if (is_array($decodedMeta)) $meta = $decodedMeta;
            }
            $commentNumber = isset($meta['comment_number']) ? (string)$meta['comment_number'] : '';
            $commentText = isset($meta['comment_text']) ? (string)$meta['comment_text'] : '';
            $winnerLine = '👤 ' . $userName;
            if ($commentNumber !== '') $winnerLine .= ' — число ' . $commentNumber;
            $winnersNames[] = $winnerLine;
            $promoRow = reserve_promocode($pdo, $id, $uid, $userName);
            $promoCodeText = $promoRow ? $promoRow['code'] : 'ПРОМОКОД ВРЕМЕННО НЕДОСТУПЕН';
            $personalText = str_replace(['{title}', '{promocode}', '{comment_number}', '{comment_text}'], [$raffle['title'], $promoCodeText, $commentNumber, $commentText], $winnerTemplate);
            $res = vk_send_message($uid, $personalText);
            save_chat_message($uid, null, $personalText, 'out', 'text', null, vk_get_sent_message_id($res), 'vk');
            if ($promoRow) {
                $errorText = vk_response_error_text($res);
                if ($errorText !== null) {
                    mark_promocode_failed($pdo, $promoRow['id'], $errorText);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ VK promo send failed for user {$uid}, code {$promoRow['code']}: {$errorText}\n", FILE_APPEND);
                } else {
                    mark_promocode_sent($pdo, $promoRow['id']);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ VK promo sent to user {$uid}, code {$promoRow['code']}\n", FILE_APPEND);
                }
            }
        }
        $winnersString = implode("\n", $winnersNames);
        $congratsText = str_replace(['{title}', '{winners}'], [$raffle['title'], $winnersString], $winnerPostTemplate);
    } else {
        $congratsText = str_replace(['{title}', '{winners}'], [$raffle['title'], ''], $noParticipantsTemplate);
    }

    $postRes = vk_wall_post($raffle['channel_id'], $congratsText, null);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " 📣 VK winners post resp: " . json_encode($postRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    $winnerIds = array_values(array_filter(array_unique(array_map(static function ($row) { return (string)($row['user_id'] ?? ''); }, $winnersList))));
    if (!empty($raffle['notify_non_winners']) && !empty($initialPool)) {
        foreach ($initialPool as $user) {
            $uid = (string)($user['user_id'] ?? '');
            if ($uid === '' || in_array($uid, $winnerIds, true)) continue;
            $text = str_replace(['{title}', '{promocode}', '{winners}', '{comment_number}', '{comment_text}'], [$raffle['title'], '', '', '', ''], $nonWinnerTemplate);
            $res = vk_send_message($uid, $text);
            save_chat_message($uid, null, $text, 'out', 'text', null, vk_get_sent_message_id($res), 'vk');
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ℹ️ VK non-winner notify to {$uid}: " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        }
    }
    $winnersJson = json_encode($winnersList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE raffles SET status = 'finished', winners_data = ? WHERE id = ?")->execute([$winnersJson, $id]);
}

function telegram_detect_media_type_from_url(?string $mediaUrl): string {
    if (!$mediaUrl) {
        return 'file';
    }

    $baseUrl = rtrim(PROJECT_URL, '/');
    if (strpos($mediaUrl, $baseUrl . '/uploads/') === 0) {
        $relativePath = substr($mediaUrl, strlen($baseUrl));
        $fullPath = __DIR__ . $relativePath;
        if (is_file($fullPath)) {
            return detect_raffle_media_type($fullPath);
        }
    }

    $path = parse_url($mediaUrl, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
    if (in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true)) return 'video';
    return 'file';
}

function telegram_send_raffle_post(array $raffle): array {
    $chatId = $raffle['channel_id'];
    $text = (string)($raffle['text'] ?? '');
    $keyboard = telegram_build_raffle_keyboard((string)$raffle['id']);
    $mediaUrl = trim((string)($raffle['media_url'] ?? ''));

    if ($mediaUrl !== '') {
        $mediaType = telegram_detect_media_type_from_url($mediaUrl);

        // Если текст короткий, кнопки вешаем прямо на медиа-пост.
        if (mb_strlen($text, 'UTF-8') <= 900) {
            if ($mediaType === 'image') {
                return telegram_send_photo($chatId, $mediaUrl, $text, $keyboard);
            }
            if ($mediaType === 'video') {
                return telegram_send_video($chatId, $mediaUrl, $text, $keyboard);
            }
        }

        // Если текст длинный, сначала публикуем медиа, потом отдельный текстовый пост с кнопкой участия.
        if ($mediaType === 'image') {
            telegram_send_photo($chatId, $mediaUrl, '');
        } elseif ($mediaType === 'video') {
            telegram_send_video($chatId, $mediaUrl, '');
        } else {
            telegram_send_document($chatId, $mediaUrl, '');
        }
    }

    return telegram_send_message($chatId, $text, $keyboard);
}

function telegram_edit_raffle_post(array $raffle, string $newText): array {
    $msgId = $raffle['post_message_id'] ?? null;
    if (!$msgId) {
        return ['ok' => false, 'success' => false, 'description' => 'Telegram message_id не найден в базе'];
    }

    $keyboard = telegram_build_raffle_keyboard((string)$raffle['id']);
    $mediaUrl = trim((string)($raffle['media_url'] ?? ''));
    if ($mediaUrl !== '' && mb_strlen($newText, 'UTF-8') <= 900) {
        $mediaType = telegram_detect_media_type_from_url($mediaUrl);
        if ($mediaType === 'image' || $mediaType === 'video') {
            return telegram_edit_message_caption($raffle['channel_id'], $msgId, $newText, $keyboard);
        }
    }

    return telegram_edit_message_text($raffle['channel_id'], $msgId, $newText, $keyboard);
}

function telegram_response_error_text($res): ?string {
    if (!is_array($res)) {
        return 'Telegram API returned non-array/null response';
    }
    if (!empty($res['ok'])) {
        return null;
    }
    return $res['description'] ?? $res['message'] ?? 'Telegram send failed';
}

$lockFile = __DIR__ . '/data/cron.lock';
if (!file_exists(dirname($lockFile))) {
    mkdir(dirname($lockFile), 0777, true);
}
$lockHandle = fopen($lockFile, 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit("Cron is already running.\n");
}

$logFile = __DIR__ . '/data/cron_run.log';
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $pdo = get_db_connection();

    try { $pdo->exec("ALTER TABLE channels MODIFY COLUMN id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles MODIFY COLUMN channel_id VARCHAR(64) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffle_promocodes MODIFY COLUMN assigned_to_user_id VARCHAR(64) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcast_recipients MODIFY COLUMN user_id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN winners_data JSON AFTER winners_count"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN notify_non_winners TINYINT(1) NOT NULL DEFAULT 0 AFTER check_subscription"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_like TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_non_winners"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_comment TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_require_like"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_comment_mode ENUM('word','number') NOT NULL DEFAULT 'word' AFTER vk_require_comment"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_comment_word VARCHAR(255) NULL AFTER vk_comment_mode"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_number_min INT NULL AFTER vk_comment_word"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_number_max INT NULL AFTER vk_number_min"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_case_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_number_max"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_bot_message TINYINT(1) NOT NULL DEFAULT 1 AFTER vk_case_sensitive"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE participants ADD COLUMN meta TEXT NULL AFTER name"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS vk_comment_events (id INT AUTO_INCREMENT PRIMARY KEY, raffle_id VARCHAR(64) NOT NULL, owner_id BIGINT NOT NULL, post_id BIGINT NOT NULL, comment_id BIGINT NOT NULL, user_id VARCHAR(64) NOT NULL, vk_user_id BIGINT NOT NULL, text TEXT NULL, created_at INT NULL, accepted TINYINT(1) NOT NULL DEFAULT 0, skip_reason VARCHAR(64) NULL, meta TEXT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_vk_comment (owner_id, post_id, comment_id), KEY idx_raffle_id (raffle_id), KEY idx_user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}

    ensure_vk_group_channel($pdo);

    $settingsRes = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $winnerTemplate = $settingsRes['winner_template'] ?? "Поздравляем! 🎈 Ты выиграл в розыгрыше: {title}!\n\nТвой промокод:\n{promocode}";
    $winnerPostTemplate = $settingsRes['winner_post_template'] ?? "🏆 Итоги: {title}\n\nПоздравляем победителей:\n{winners}";
    $nonWinnerTemplate = $settingsRes['non_winner_template'] ?? "Спасибо за участие в розыгрыше {title}. В этот раз удача улыбнулась другим, но впереди ещё будут новые розыгрыши 🍀";
    $vkPostFooterTemplate = $settingsRes['vk_post_footer_template'] ?? "{comment_instruction}
{like_instruction}
{subscription_instruction}
{bot_instruction}

Победитель получит промокод в личные сообщения сообщества.";
    $vkWinnerTemplate = $settingsRes['vk_winner_template'] ?? $winnerTemplate;
    $vkWinnerPostTemplate = $settingsRes['vk_winner_post_template'] ?? $winnerPostTemplate;
    $vkNonWinnerTemplate = $settingsRes['vk_non_winner_template'] ?? $nonWinnerTemplate;
    $vkNoParticipantsTemplate = $settingsRes['vk_no_participants_template'] ?? "😔 В розыгрыше {title} нет участников, которые выполнили условия.";

    $nowTimestamp = time();
    $botUsername = defined('BOT_USERNAME') ? BOT_USERNAME : 'bot';

    $stmtRaffles = $pdo->query("SELECT * FROM raffles WHERE status IN ('pending', 'active') AND (platform IN ('max', 'telegram', 'vk') OR platform IS NULL OR platform = '')");
    $raffles = $stmtRaffles->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raffles as $raffle) {
        $id = $raffle['id'];
        $platform = raffle_platform($raffle);
        $startTimestamp = strtotime($raffle['start_date']);
        $endTimestamp = strtotime($raffle['end_date']);

        if ($raffle['status'] === 'pending' && $startTimestamp <= $nowTimestamp) {
            if ($platform === 'vk') {
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ Publishing VK raffle $id...\n", FILE_APPEND);
                $response = vk_send_raffle_post($raffle, $vkPostFooterTemplate);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " VK DEBUG RESP: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

                if (empty($response['success'])) {
                    $err = $response['error']['error_msg'] ?? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ VK publish error: " . $err . "\n", FILE_APPEND);
                } else {
                    $postRef = vk_wall_post_ref($response, $raffle['channel_id']);
                    $upd = $pdo->prepare("UPDATE raffles SET status = 'active', post_message_id = ? WHERE id = ?");
                    $upd->execute([$postRef, $id]);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " VK success! Post: " . ($postRef ?? 'NOT FOUND') . "\n", FILE_APPEND);
                }
                continue;
            }

            if ($platform === 'telegram') {
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ Publishing Telegram raffle $id...\n", FILE_APPEND);
                $response = telegram_send_raffle_post($raffle);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " TG DEBUG RESP: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

                $errorText = telegram_response_error_text($response);
                if ($errorText !== null) {
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ Telegram publish error: " . $errorText . "\n", FILE_APPEND);
                } else {
                    $msgId = telegram_get_sent_message_id($response);
                    $upd = $pdo->prepare("UPDATE raffles SET status = 'active', post_message_id = ? WHERE id = ?");
                    $upd->execute([$msgId, $id]);
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " Telegram success! MsgID: " . ($msgId ?? 'NOT FOUND') . "\n", FILE_APPEND);
                }
                continue;
            }

            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ Publishing raffle $id...\n", FILE_APPEND);
            $deepLink = "https://max.ru/{$botUsername}?startapp={$id}";

            $attachments = [];
            if (!empty($raffle['media_url'])) {
                try {
                    $mediaAttachment = build_raffle_media_attachment($raffle['media_url']);
                    if ($mediaAttachment) {
                        $attachments[] = $mediaAttachment;
                    }
                } catch (Exception $e) {
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ Media prepare failed for raffle $id: " . $e->getMessage() . "\n", FILE_APPEND);
                    continue;
                }
            }

            $attachments[] = [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [[
                        ['type' => 'link', 'text' => 'Участвовать 🎁', 'url' => $deepLink]
                    ]]
                ]
            ];

            $response = max_send_message($raffle['channel_id'], $raffle['text'], $attachments);
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " DEBUG RESP: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

            if (isset($response['code']) || (isset($response['success']) && $response['success'] === false) || $response === null) {
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ Error: " . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
            } else {
                $msgId = get_sent_message_id($response);
                $upd = $pdo->prepare("UPDATE raffles SET status = 'active', post_message_id = ? WHERE id = ?");
                $upd->execute([$msgId, $id]);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " Success! MsgID: " . ($msgId ?? 'NOT FOUND') . "\n", FILE_APPEND);
            }
        }

        if ($raffle['status'] === 'active' && $platform === 'vk' && $endTimestamp > $nowTimestamp) {
            vk_collect_raffle_participants($pdo, $raffle, $logFile);
            continue;
        }

        if ($raffle['status'] === 'active' && $endTimestamp <= $nowTimestamp) {
            if ($platform === 'vk') {
                finish_vk_raffle($pdo, $raffle, $logFile, $vkWinnerTemplate, $vkWinnerPostTemplate, $vkNonWinnerTemplate, $vkNoParticipantsTemplate);
                continue;
            }
            if ($platform === 'telegram') {
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " 🏁 Finishing Telegram raffle $id...\n", FILE_APPEND);
                $pdo->prepare("UPDATE raffles SET status = 'processing' WHERE id = ?")->execute([$id]);

                $stmtParts = $pdo->prepare("SELECT user_id, name FROM participants WHERE raffle_id = ?");
                $stmtParts->execute([$id]);
                $initialPool = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

                $finalPool = [];
                if (!empty($raffle['check_subscription'])) {
                    foreach ($initialPool as $userData) {
                        if (telegram_check_membership($raffle['channel_id'], $userData['user_id'])) {
                            $finalPool[] = $userData;
                        }
                    }
                } else {
                    $finalPool = $initialPool;
                }

                $winnersCount = (int)$raffle['winners_count'];
                $winnersList = [];
                $winnersNames = [];

                if (count($finalPool) > 0) {
                    if (count($finalPool) <= $winnersCount) {
                        $winnersList = $finalPool;
                    } else {
                        $winnerKeys = array_rand($finalPool, $winnersCount);
                        if (!is_array($winnerKeys)) $winnerKeys = [$winnerKeys];
                        foreach ($winnerKeys as $k) {
                            $winnersList[] = $finalPool[$k];
                        }
                    }

                    foreach ($winnersList as $user) {
                        $uid = $user['user_id'];
                        $userName = $user['name'] ?? 'Счастливчик';
                        $winnersNames[] = '👤 ' . $userName;

                        $promoRow = reserve_promocode($pdo, $raffle['id'], $uid, $userName);
                        $promoCodeText = $promoRow ? $promoRow['code'] : 'ПРОМОКОД ВРЕМЕННО НЕДОСТУПЕН';

                        $personalText = str_replace(
                            ['{title}', '{promocode}'],
                            [$raffle['title'], $promoCodeText],
                            $winnerTemplate
                        );

                        $res = telegram_send_message($uid, $personalText);
                        save_chat_message($uid, null, $personalText, 'out', 'text', null, telegram_get_sent_message_id($res), 'telegram');

                        if ($promoRow) {
                            $errorText = telegram_response_error_text($res);
                            if ($errorText !== null) {
                                mark_promocode_failed($pdo, $promoRow['id'], $errorText);
                                @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ Telegram promo send failed for user {$uid}, code {$promoRow['code']}: {$errorText}\n", FILE_APPEND);
                            } else {
                                mark_promocode_sent($pdo, $promoRow['id']);
                                @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ Telegram promo sent to user {$uid}, code {$promoRow['code']}\n", FILE_APPEND);
                            }
                        }
                    }

                    $winnersString = implode("\n", $winnersNames);
                    $congratsText = str_replace(['{title}', '{winners}'], [$raffle['title'], $winnersString], $winnerPostTemplate);
                } else {
                    $congratsText = "😔 В розыгрыше {$raffle['title']} нет участников (или все отписались).";
                }

                $channelRes = telegram_send_message($raffle['channel_id'], $congratsText);
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " 📣 Telegram winners post resp: " . json_encode($channelRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

                $winnerIds = array_map(static function ($row) { return (string)($row['user_id'] ?? ''); }, $winnersList);
                $winnerIds = array_values(array_filter(array_unique($winnerIds)));

                if (!empty($raffle['notify_non_winners']) && !empty($initialPool)) {
                    foreach ($initialPool as $user) {
                        $uid = (string)($user['user_id'] ?? '');
                        if ($uid === '' || in_array($uid, $winnerIds, true)) {
                            continue;
                        }

                        $text = str_replace(['{title}', '{promocode}', '{winners}', '{comment_number}', '{comment_text}'], [$raffle['title'], '', '', '', ''], $nonWinnerTemplate);
                        $res = telegram_send_message($uid, $text);
                        save_chat_message($uid, null, $text, 'out', 'text', null, telegram_get_sent_message_id($res), 'telegram');
                        @file_put_contents($logFile, date('Y-m-d H:i:s') . " ℹ️ Telegram non-winner notify to {$uid}: " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
                    }
                }

                $winnersJson = json_encode($winnersList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $pdo->prepare("UPDATE raffles SET status = 'finished', winners_data = ? WHERE id = ?")->execute([$winnersJson, $id]);
                continue;
            }

            @file_put_contents($logFile, date('Y-m-d H:i:s') . " 🏁 Finishing raffle $id...\n", FILE_APPEND);
            $pdo->prepare("UPDATE raffles SET status = 'processing' WHERE id = ?")->execute([$id]);

            $stmtParts = $pdo->prepare("SELECT user_id, name FROM participants WHERE raffle_id = ?");
            $stmtParts->execute([$id]);
            $initialPool = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

            $finalPool = [];
            if (!empty($raffle['check_subscription'])) {
                foreach ($initialPool as $userData) {
                    if (max_check_membership($raffle['channel_id'], $userData['user_id'])) {
                        $finalPool[] = $userData;
                    }
                }
            } else {
                $finalPool = $initialPool;
            }

            $winnersCount = (int)$raffle['winners_count'];
            $winnersList = [];
            $winnersNames = [];

            if (count($finalPool) > 0) {
                if (count($finalPool) <= $winnersCount) {
                    $winnersList = $finalPool;
                } else {
                    $winnerKeys = array_rand($finalPool, $winnersCount);
                    if (!is_array($winnerKeys)) $winnerKeys = [$winnerKeys];
                    foreach ($winnerKeys as $k) {
                        $winnersList[] = $finalPool[$k];
                    }
                }

                foreach ($winnersList as $user) {
                    $uid = $user['user_id'];
                    $userName = $user['name'] ?? 'Счастливчик';
                    $winnersNames[] = '👤 ' . $userName;

                    $promoRow = reserve_promocode($pdo, $raffle['id'], $uid, $userName);
                    $promoCodeText = $promoRow ? $promoRow['code'] : 'ПРОМОКОД ВРЕМЕННО НЕДОСТУПЕН';

                    $personalText = str_replace(
                        ['{title}', '{promocode}'],
                        [$raffle['title'], $promoCodeText],
                        $winnerTemplate
                    );

                    $res = max_send_message($uid, $personalText, null, true);
                    save_chat_message($uid, null, $personalText, 'out', 'text', null, get_sent_message_id($res));

                    if ($promoRow) {
                        $sendFailed = false;
                        $errorText = null;

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

                        if ($sendFailed) {
                            mark_promocode_failed($pdo, $promoRow['id'], $errorText);
                            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ❌ Promo send failed for user {$uid}, code {$promoRow['code']}: {$errorText}\n", FILE_APPEND);
                        } else {
                            mark_promocode_sent($pdo, $promoRow['id']);
                            @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ Promo sent to user {$uid}, code {$promoRow['code']}\n", FILE_APPEND);
                        }
                    }
                }

                $winnersString = implode("\n", $winnersNames);
                $congratsText = str_replace(['{title}', '{winners}'], [$raffle['title'], $winnersString], $winnerPostTemplate);
            } else {
                $congratsText = "😔 В розыгрыше {$raffle['title']} нет участников (или все отписались).";
            }

            $channelRes = max_send_message($raffle['channel_id'], $congratsText);
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " 📣 Winners post resp: " . json_encode($channelRes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

            $winnerIds = array_map(static function ($row) { return (string)($row['user_id'] ?? ''); }, $winnersList);
            $winnerIds = array_values(array_filter(array_unique($winnerIds)));

            if (!empty($raffle['notify_non_winners']) && !empty($initialPool)) {
                foreach ($initialPool as $user) {
                    $uid = (string)($user['user_id'] ?? '');
                    if ($uid === '' || in_array($uid, $winnerIds, true)) {
                        continue;
                    }

                    $text = str_replace(['{title}', '{promocode}', '{winners}', '{comment_number}', '{comment_text}'], [$raffle['title'], '', '', '', ''], $nonWinnerTemplate);
                    $res = max_send_message($uid, $text, null, true);
                    save_chat_message($uid, null, $text, 'out', 'text', null, get_sent_message_id($res));
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ℹ️ Non-winner notify to {$uid}: " . json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
                }
            }

            $winnersJson = json_encode($winnersList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare("UPDATE raffles SET status = 'finished', winners_data = ? WHERE id = ?")->execute([$winnersJson, $id]);
        }
    }
} catch (Exception $e) {
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " FATAL ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
?>
