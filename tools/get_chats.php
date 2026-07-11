<?php
require_once __DIR__ . '/../lib/max_api.php';

// Функция для получения списка чатов
function get_chats() {
    $url = MAX_BOT_API_BASE_URL . 'chats'; 
    // Третий параметр 'GET' теперь работает благодаря обновлению max_api.php
    return max_request($url, [], 'GET');
}

$response = get_chats();

echo "<pre style='background: #222; color: #fff; padding: 20px;'>";
echo "<h1>📋 Список чатов, где бот админ или участник:</h1>";

// Согласно документации, массив чатов лежит в поле 'chats'
if (isset($response['chats']) && is_array($response['chats'])) {
    foreach ($response['chats'] as $chat) {
        // Пытаемся угадать поля, так как в документации не раскрыт объект Chat,
        // обычно это id/chat_id и title/name. Выведем всё, что есть.
        
        $id = $chat['id'] ?? $chat['chat_id'] ?? 'ID не найден';
        $title = $chat['title'] ?? $chat['name'] ?? 'Без названия';
        
        echo "<div style='border: 1px solid #555; padding: 10px; margin-bottom: 10px;'>";
        echo "<b>Название:</b> " . htmlspecialchars($title) . "\n";
        echo "<b>ID (копируй это):</b> <span style='background: #d32f2f; padding: 2px 5px;'>$id</span>\n";
        
        // Выведем весь массив чата, чтобы ты точно увидел структуру, если поля другие
        echo "\n<small>Полные данные чата:</small>\n";
        print_r($chat); 
        echo "</div>";
    }
    
    if (empty($response['chats'])) {
        echo "Список чатов пуст. Убедись, что бот добавлен в канал/группу и написал туда хотя бы одно сообщение (или его сделали админом).";
    }

} else {
    echo "Не удалось получить список чатов (ключ 'chats' отсутствует).\n";
    echo "Полный ответ API:\n";
    print_r($response);
}
echo "</pre>";
?>