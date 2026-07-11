<?php
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/max_api.php';
require_once __DIR__ . '/lib/chat.php';

// Лог отладки (оставляем, полезно)
define('DEBUG_LOG', __DIR__ . '/data/webhook_debug.log');

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) exit('No data');

// Логируем
$logEntry = date('Y-m-d H:i:s') . " RECEIVED:\n" . print_r($update, true) . "\n----------------\n";
file_put_contents(DEBUG_LOG, $logEntry, FILE_APPEND);

// Поиск ID
function find_user_id_recursive($data) {
    if (is_array($data)) {
        if (isset($data['user_id'])) return $data['user_id'];
        if (isset($data['chat_id'])) return $data['chat_id'];
        if (isset($data['sender']['user_id'])) return $data['sender']['user_id'];
        if (isset($data['user']['id'])) return $data['user']['id'];
        foreach ($data as $val) {
            $res = find_user_id_recursive($val);
            if ($res) return $res;
        }
    }
    return null;
}

// Поиск Имени
function find_user_name_recursive($data) {
    if (is_array($data)) {
        if (!empty($data['name'])) return $data['name'];
        if (!empty($data['first_name'])) return $data['first_name'];
        if (isset($data['sender']['name'])) return $data['sender']['name'];
        if (isset($data['user']['name'])) return $data['user']['name'];
        foreach ($data as $val) {
            $res = find_user_name_recursive($val);
            if ($res) return $res;
        }
    }
    return null;
}

// Вспомогательная функция для безопасного извлечения ID отправленного ботом сообщения
function get_sent_message_id($res) {
    if (isset($res['message']['body']['mid'])) return $res['message']['body']['mid'];
    if (isset($res['message']['id'])) return $res['message']['id'];
    if (isset($res['message_id'])) return $res['message_id'];
    if (isset($res['data']['message_id'])) return $res['data']['message_id'];
    if (isset($res['id'])) return $res['id'];
    return null;
}

// 1. CALLBACK
if (($update['update_type'] ?? '') === 'message_callback') {
    $callback = $update['callback'] ?? [];
    $callbackId = $callback['callback_id'] ?? null;
    $payload = $callback['payload'] ?? '';
    $userId = $callback['user_id'] ?? null;
    $userName = "ID: $userId"; 

    if ($callbackId && strpos($payload, 'participate_') === 0) {
        $raffleId = str_replace('participate_', '', $payload);
        
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ?");
        $stmt->execute([$raffleId]);
        $raffle = $stmt->fetch();
        
        if (!$raffle || $raffle['status'] !== 'active') {
            max_answer_callback($callbackId, "⛔ Розыгрыш завершен.");
            exit;
        }

        if (!empty($raffle['check_subscription'])) {
            $isMember = max_check_membership($raffle['channel_id'], $userId);
            if (!$isMember) {
                max_answer_callback($callbackId, "🔒 Для участия нужно подписаться на канал!");
                exit; 
            }
        }

        $stmtCheck = $pdo->prepare("SELECT 1 FROM participants WHERE raffle_id = ? AND user_id = ?");
        $stmtCheck->execute([$raffleId, $userId]);
        if ($stmtCheck->fetch()) {
            max_answer_callback($callbackId, "✅ Ты уже участвуешь!");
            exit;
        }

        $stmtInsert = $pdo->prepare("INSERT INTO participants (raffle_id, user_id, name) VALUES (?, ?, ?)");
        $stmtInsert->execute([$raffleId, $userId, $userName]);
        
        max_answer_callback($callbackId, "🎉 Ура! Ты в списке участников!");
        
        // Логируем факт участия как системное сообщение
        save_chat_message($userId, null, "✅ Нажал кнопку участия в: " . $raffle['title'], 'in');
    }
}

// 2. BOT_STARTED
elseif (($update['update_type'] ?? '') === 'bot_started') {
    $data = $update['bot_started'] ?? [];
    
    $userId = $data['user_id'] ?? null;
    if (!$userId) $userId = find_user_id_recursive($update);

    $foundName = find_user_name_recursive($data); 
    if (!$foundName) $foundName = find_user_name_recursive($update);
    $senderName = $foundName ? trim($foundName) : "ID: $userId";
    
    $payload = $data['payload'] ?? ''; 

    if ($userId) {
        // Сохраняем входящее действие (здесь нет mid, т.к. это системное событие, а не текстовое сообщение)
        $virtualText = "/start" . ($payload ? " $payload" : "");
        save_chat_message($userId, $senderName, $virtualText, 'in');

        // Отправляем ответ и СОХРАНЯЕМ его (и извлекаем исходящий ID сообщения)
        if ($payload === 'test') {
            $reply = "Ожидайте итогов розыгрыша, мы вас оповестим, если Вы победите! 🍀";
            $res = max_send_message($userId, $reply, null, true);
            save_chat_message($userId, null, $reply, 'out', 'text', null, get_sent_message_id($res));
        } else {
            $reply = "Привет! Уже участвуешь в розыгрыше? Тогда будем вместе ждать результата 🍀";
            $res = max_send_message($userId, $reply, null, true);
            save_chat_message($userId, null, $reply, 'out', 'text', null, get_sent_message_id($res));
        }
    }
}

// 3. MESSAGE_CREATED
elseif (($update['update_type'] ?? '') === 'message_created') {
    $msg = $update['message'] ?? [];
    $userId = $msg['sender']['user_id'] ?? null;
    if (!$userId) $userId = find_user_id_recursive($msg);

    $text = trim($msg['body']['text'] ?? '');
    
    // ИЗВЛЕКАЕМ ID ВХОДЯЩЕГО СООБЩЕНИЯ согласно документации: message -> body -> mid
    $incomingMsgId = $msg['body']['mid'] ?? $msg['id'] ?? null;

    $firstName = $msg['sender']['name'] ?? '';
    if (!$firstName) $firstName = find_user_name_recursive($msg);
    $senderName = $firstName ? trim($firstName) : "ID: $userId";

    if ($userId) {
        // Сохраняем входящее сообщение с его ID
        save_chat_message($userId, $senderName, $text, 'in', 'text', null, $incomingMsgId);

        // Ответы
        if (strpos($text, '/start test') === 0) {
            $reply = "Ожидайте итогов розыгрыша, мы вас оповестим, если Вы победите! 🍀";
            $res = max_send_message($userId, $reply, null, true);
            save_chat_message($userId, null, $reply, 'out', 'text', null, get_sent_message_id($res));
        }
        elseif ($text === '/start') {
            $reply = "Привет! Уже участвуешь в розыгрыше? Тогда будем вместе ждать результата 🍀";
            $res = max_send_message($userId, $reply, null, true);
            save_chat_message($userId, null, $reply, 'out', 'text', null, get_sent_message_id($res));
        }
    }
}

echo 'OK';
?>