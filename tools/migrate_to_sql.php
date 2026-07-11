<?php
require_once __DIR__ . '/../lib/db.php';

$pdo = get_db_connection();
echo "<pre style='background:#111; color:#0f0; padding:20px;'>";
echo "Начинаем миграцию...\n\n";

try {
    $pdo->beginTransaction();

    // 1. Миграция пользователей и истории сообщений
    $usersFile = __DIR__ . '/../data/users.json';
    $historyDir = __DIR__ . '/../data/history';

    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true) ?: [];
        $stmtUser = $pdo->prepare("INSERT IGNORE INTO users (id, name, mess_date, unread, status) VALUES (?, ?, ?, ?, ?)");
        $stmtMsg = $pdo->prepare("INSERT INTO messages (user_id, sender_name, direction, type, content, msg_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($users as $userId => $userData) {
            $stmtUser->execute([
                $userId, 
                $userData['name'] ?? "ID: $userId", 
                $userData['mess_date'] ?? time(), 
                $userData['unread'] ?? 0, 
                $userData['status'] ?? ''
            ]);
            
            $historyFile = $historyDir . '/' . $userId . '.json';
            if (file_exists($historyFile)) {
                $history = json_decode(file_get_contents($historyFile), true) ?: [];
                foreach ($history as $msg) {
                    $stmtMsg->execute([
                        $userId,
                        $userData['name'] ?? "ID: $userId",
                        $msg['dir'] ?? 'in',
                        $msg['type'] ?? 'text',
                        $msg['content'] ?? '',
                        $msg['msg_id'] ?? null,
                        $msg['time'] ?? time()
                    ]);
                }
            }
        }
        echo "✅ Пользователи и история перенесены.\n";
    }

    // 2. Миграция розыгрышей
    $rafflesFile = __DIR__ . '/../data/raffles.json';
    if (file_exists($rafflesFile)) {
        $raffles = json_decode(file_get_contents($rafflesFile), true) ?: [];
        $stmtRaffle = $pdo->prepare("INSERT IGNORE INTO raffles (id, title, channel_id, check_subscription, status) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($raffles as $raffleId => $rData) {
            $stmtRaffle->execute([
                $raffleId,
                $rData['title'] ?? 'Без названия',
                $rData['channel_id'] ?? '',
                isset($rData['check_subscription']) ? (int)$rData['check_subscription'] : 0,
                $rData['status'] ?? 'active'
            ]);
        }
        echo "✅ Розыгрыши перенесены.\n";
    }

    // 3. Миграция участников
    $participantsFile = __DIR__ . '/../data/participants.json';
    if (file_exists($participantsFile)) {
        $participants = json_decode(file_get_contents($participantsFile), true) ?: [];
        $stmtPart = $pdo->prepare("INSERT IGNORE INTO participants (raffle_id, user_id, name, join_date) VALUES (?, ?, ?, ?)");
        
        foreach ($participants as $raffleId => $users) {
            foreach ($users as $userId => $pData) {
                $stmtPart->execute([
                    $raffleId,
                    $userId,
                    $pData['name'] ?? "ID: $userId",
                    $pData['date'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }
        echo "✅ Участники перенесены.\n";
    }

    $pdo->commit();
    echo "\n🎉 МИГРАЦИЯ УСПЕШНО ЗАВЕРШЕНА!";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ ОШИБКА: " . $e->getMessage();
}
echo "</pre>";
?>