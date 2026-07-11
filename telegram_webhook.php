<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/telegram_api.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/logger.php';

function tg_user_display_name(array $from): string {
    $parts = [];
    if (!empty($from['first_name'])) $parts[] = trim((string)$from['first_name']);
    if (!empty($from['last_name'])) $parts[] = trim((string)$from['last_name']);
    $name = trim(implode(' ', array_filter($parts)));
    if ($name !== '') return $name;
    if (!empty($from['username'])) return '@' . trim((string)$from['username']);
    return 'Telegram ID: ' . (string)($from['id'] ?? 'unknown');
}

function tg_upsert_user(PDO $pdo, $userId, string $userName): void {
    ensure_platform_columns($pdo);
    $now = time();
    $stmt = $pdo->prepare("\n        INSERT INTO users (id, platform, name, mess_date, unread, status)\n        VALUES (?, 'telegram', ?, ?, 0, 'Telegram')\n        ON DUPLICATE KEY UPDATE\n            platform = 'telegram',\n            name = IF(? != '', ?, name),\n            mess_date = GREATEST(mess_date, ?),\n            status = 'Telegram'\n    ");
    $stmt->execute([$userId, $userName, $now, $userName, $userName, $now]);
}

function tg_answer(string $callbackId, string $text, bool $alert = false): void {
    if ($callbackId !== '') {
        telegram_answer_callback_query($callbackId, $text, $alert);
    }
}

function tg_admin_action_text(string $action, ?array $raffle = null): string {
    $title = '';
    if (is_array($raffle) && !empty($raffle['title'])) {
        $title = trim((string)$raffle['title']);
    }

    if ($title !== '') {
        return $action . ' для розыгрыша: ' . $title;
    }

    return $action;
}

$secret = defined('TG_WEBHOOK_SECRET') ? trim((string)TG_WEBHOOK_SECRET) : '';
if ($secret !== '') {
    $headerSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $headerSecret)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$raw = file_get_contents('php://input') ?: '';
$update = json_decode($raw, true);

$logDir = __DIR__ . '/data';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
@file_put_contents(
    $logDir . '/telegram_webhook_debug.log',
    '[' . date('Y-m-d H:i:s') . '] ' . $raw . PHP_EOL,
    FILE_APPEND
);

if (!is_array($update)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

try {
    $pdo = get_db_connection();
    ensure_platform_columns($pdo);

    if (isset($update['message'])) {
        $message = $update['message'];
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string)($message['text'] ?? ''));
        $from = $message['from'] ?? [];
        $userId = $from['id'] ?? $chatId;
        $userName = is_array($from) ? tg_user_display_name($from) : ('Telegram ID: ' . $userId);

        if ($userId) {
            tg_upsert_user($pdo, $userId, $userName);
            if ($text !== '' && strpos($text, '/start') !== 0) {
                save_chat_message($userId, $userName, $text, 'in', 'text', null, null, 'telegram');
            }

            // Чтобы переписка в админке не теряла входящие файлы из Telegram.
            if (!empty($message['photo']) && is_array($message['photo'])) {
                $photos = $message['photo'];
                $largest = end($photos);
                $fileId = is_array($largest) ? ($largest['file_id'] ?? '') : '';
                $caption = trim((string)($message['caption'] ?? ''));
                $content = $caption !== '' ? $caption : 'Фото из Telegram';
                if ($fileId !== '') $content .= "
file_id: " . $fileId;
                save_chat_message($userId, $userName, $content, 'in', 'text', null, null, 'telegram');
            } elseif (!empty($message['video']['file_id'])) {
                $caption = trim((string)($message['caption'] ?? ''));
                $content = ($caption !== '' ? $caption : 'Видео из Telegram') . "
file_id: " . $message['video']['file_id'];
                save_chat_message($userId, $userName, $content, 'in', 'text', null, null, 'telegram');
            } elseif (!empty($message['document']['file_id'])) {
                $fileName = $message['document']['file_name'] ?? 'Документ из Telegram';
                $content = $fileName . "
file_id: " . $message['document']['file_id'];
                save_chat_message($userId, $userName, $content, 'in', 'text', null, null, 'telegram');
            }
        }

        if ($chatId && strpos($text, '/start') === 0) {
            $payload = trim(substr($text, 6));

            if (strpos($payload, 'notify_') === 0) {
                $raffleId = substr($payload, strlen('notify_'));
                $raffle = null;
                $reply = "Уведомления включены. Если ты победишь в розыгрыше, бот сможет отправить промокод сюда.";

                if ($raffleId !== '') {
                    $stmt = $pdo->prepare("SELECT title FROM raffles WHERE id = ? AND platform = 'telegram' LIMIT 1");
                    $stmt->execute([$raffleId]);
                    $raffle = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($raffle) {
                        $reply = "Уведомления включены для розыгрыша: {$raffle['title']}. Если ты победишь, бот отправит промокод сюда.";
                    }
                }

                save_chat_message($userId, $userName, tg_admin_action_text('Пользователь включил уведомления о выигрыше', $raffle), 'in', 'text', null, null, 'telegram');
                $sent = telegram_send_message($chatId, $reply);
                save_chat_message($userId, null, $reply, 'out', 'text', null, telegram_get_sent_message_id($sent), 'telegram');
            } else {
                save_chat_message($userId, $userName, 'Пользователь запустил Telegram-бота', 'in', 'text', null, null, 'telegram');
                $reply = "Бот розыгрышей подключен. Чтобы участвовать, нажми кнопку «Участвовать» в посте розыгрыша в канале.";
                $sent = telegram_send_message($chatId, $reply);
                save_chat_message($userId, null, $reply, 'out', 'text', null, telegram_get_sent_message_id($sent), 'telegram');
            }
        }
    }

    if (isset($update['callback_query'])) {
        $callback = $update['callback_query'];
        $callbackId = (string)($callback['id'] ?? '');
        $data = (string)($callback['data'] ?? '');
        $from = $callback['from'] ?? [];
        $userId = is_array($from) ? ($from['id'] ?? null) : null;
        $userName = is_array($from) ? tg_user_display_name($from) : ('Telegram ID: ' . $userId);

        if ($callbackId === '' || $data === '') {
            http_response_code(200);
            echo 'ok';
            exit;
        }

        if (strpos($data, 'tg_participate_') === 0) {
            $raffleId = substr($data, strlen('tg_participate_'));

            if (!$userId || $raffleId === '') {
                tg_answer($callbackId, 'Не удалось определить пользователя или розыгрыш.', true);
                http_response_code(200);
                echo 'ok';
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ? AND platform = 'telegram' LIMIT 1");
            $stmt->execute([$raffleId]);
            $raffle = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$raffle || $raffle['status'] !== 'active') {
                tg_answer($callbackId, '⛔ Розыгрыш завершён или ещё не начался.', true);
                http_response_code(200);
                echo 'ok';
                exit;
            }

            if (!empty($raffle['check_subscription']) && !telegram_check_membership($raffle['channel_id'], $userId)) {
                tg_answer($callbackId, '🔒 Для участия нужно подписаться на канал.', true);
                http_response_code(200);
                echo 'ok';
                exit;
            }

            tg_upsert_user($pdo, $userId, $userName);

            $stmtCheck = $pdo->prepare("SELECT 1 FROM participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
            $stmtCheck->execute([$raffleId, $userId]);
            if ($stmtCheck->fetch()) {
                tg_answer($callbackId, '✅ Ты уже участвуешь!');
                http_response_code(200);
                echo 'ok';
                exit;
            }

            $stmtInsert = $pdo->prepare("INSERT INTO participants (raffle_id, user_id, name) VALUES (?, ?, ?)");
            $stmtInsert->execute([$raffleId, $userId, $userName]);

            save_chat_message($userId, $userName, tg_admin_action_text('Пользователь нажал кнопку «Участвовать»', $raffle), 'in', 'text', null, null, 'telegram');
            tg_answer($callbackId, '🎉 Ура! Ты в списке участников.');
            AppLogger::info('Telegram participant registered', [
                'user_id' => $userId,
                'user_name' => $userName,
                'raffle_id' => $raffleId,
            ]);
        } else {
            tg_answer($callbackId, 'Неизвестная кнопка.', false);
        }
    }
} catch (Throwable $e) {
    AppLogger::error('Telegram webhook error', ['update' => $update], $e);
}

http_response_code(200);
echo 'ok';
