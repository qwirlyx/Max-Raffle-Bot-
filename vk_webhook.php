<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/vk_api.php';
require_once __DIR__ . '/lib/logger.php';

header('Content-Type: text/plain; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
@file_put_contents(__DIR__ . '/data/vk_webhook_debug.log', date('Y-m-d H:i:s') . ' ' . $raw . PHP_EOL, FILE_APPEND);

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    echo 'ok';
    exit;
}

$secret = defined('VK_SECRET_KEY') ? trim((string)VK_SECRET_KEY) : '';
if ($secret !== '' && (!isset($payload['secret']) || !hash_equals($secret, (string)$payload['secret']))) {
    AppLogger::warning('VK webhook: неверный secret', ['type' => $payload['type'] ?? '']);
    echo 'ok';
    exit;
}

$type = (string)($payload['type'] ?? '');

if ($type === 'confirmation') {
    echo defined('VK_CONFIRMATION_CODE') ? (string)VK_CONFIRMATION_CODE : '';
    exit;
}

if ($type === 'message_new') {
    try {
        $message = $payload['object']['message'] ?? $payload['object'] ?? [];
        $fromId = $message['from_id'] ?? null;
        if ($fromId) {
            $chatUserId = vk_chat_user_id($fromId);
            $text = trim((string)($message['text'] ?? ''));
            $attachments = $message['attachments'] ?? [];
            if ($text === '' && is_array($attachments) && count($attachments) > 0) {
                $types = [];
                foreach ($attachments as $a) {
                    if (!empty($a['type'])) $types[] = $a['type'];
                }
                $text = 'Пользователь отправил вложение' . (!empty($types) ? ': ' . implode(', ', array_unique($types)) : '');
            }
            if ($text === '') {
                $text = 'Пользователь отправил пустое сообщение';
            }
            $senderName = vk_get_user_display_name($fromId);
            $msgId = isset($message['id']) ? (string)$message['id'] : null;
            save_chat_message($chatUserId, $senderName, $text, 'in', 'text', null, $msgId, 'vk');
        }
    } catch (Throwable $e) {
        AppLogger::error('VK webhook message_new error', ['payload' => $payload], $e);
    }
}


if ($type === 'wall_reply_new') {
    try {
        $pdo = get_db_connection();
        ensure_platform_columns($pdo);
        $comment = $payload['object'] ?? [];
        $fromId = (int)($comment['from_id'] ?? 0);
        $postId = (int)($comment['post_id'] ?? 0);
        $ownerId = (int)($comment['owner_id'] ?? ($comment['post_owner_id'] ?? 0));
        $commentId = (int)($comment['id'] ?? 0);
        $text = (string)($comment['text'] ?? '');
        $createdAt = isset($comment['date']) ? (int)$comment['date'] : time();

        if ($fromId > 0 && $postId > 0 && $ownerId !== 0 && $commentId > 0) {
            $raffle = vk_find_raffle_by_wall($pdo, $ownerId, $postId);
            if ($raffle) {
                vk_save_comment_event($pdo, (string)$raffle['id'], $ownerId, $postId, $commentId, $fromId, $text, $createdAt);
                $stats = vk_process_comment_events_for_raffle($pdo, $raffle, null);
                AppLogger::info('VK wall_reply_new processed', ['raffle_id' => $raffle['id'], 'stats' => $stats]);
            } else {
                AppLogger::warning('VK wall_reply_new: raffle not found', ['owner_id' => $ownerId, 'post_id' => $postId, 'from_id' => $fromId]);
            }
        }
    } catch (Throwable $e) {
        AppLogger::error('VK webhook wall_reply_new error', ['payload' => $payload], $e);
    }
}

echo 'ok';
exit;
?>
