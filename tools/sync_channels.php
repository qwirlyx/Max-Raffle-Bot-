<?php
require_once __DIR__ . '/../lib/max_api.php';
require_once __DIR__ . '/../lib/db.php';

function sync_channels_to_db() {
    $url = MAX_BOT_API_BASE_URL . 'chats'; 
    $response = max_request($url, [], 'GET');
    
    if (isset($response['chats']) && is_array($response['chats'])) {
        $pdo = get_db_connection();
        
        try { $pdo->exec("ALTER TABLE channels ADD COLUMN platform ENUM('max','telegram') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}

        $stmt = $pdo->prepare("
            INSERT INTO channels (id, platform, title, raw_data) 
            VALUES (?, 'max', ?, ?)
            ON DUPLICATE KEY UPDATE 
                platform = 'max',
                title = VALUES(title),
                raw_data = VALUES(raw_data)
        ");
        
        $count = 0;
        foreach ($response['chats'] as $chat) {
            $id = $chat['id'] ?? $chat['chat_id'] ?? null;
            $title = $chat['title'] ?? $chat['name'] ?? 'Без названия';
            
            if ($id) {
                $stmt->execute([
                    $id, 
                    $title, 
                    json_encode($chat, JSON_UNESCAPED_UNICODE)
                ]);
                $count++;
            }
        }
        return ['success' => true, 'count' => $count, 'message' => "Синхронизировано чатов: $count"];
    }
    return ['success' => false, 'message' => 'Не удалось получить данные от API MAX'];
}

// Позволяет запускать как напрямую в браузере, так и через fetch() в админке
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(sync_channels_to_db());
    exit;
} else {
    $result = sync_channels_to_db();
    echo "<pre style='background:#222; color:#fff; padding:20px;'>";
    echo $result['message'];
    echo "</pre>";
}
?>