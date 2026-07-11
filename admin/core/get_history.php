<?php
require_once __DIR__ . "/session.php";
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/db.php';

function normalize_telegram_admin_history_content($content) {
    $text = trim((string)$content);

    if (preg_match('/^\/start\s+notify_[a-f0-9-]{20,}$/iu', $text)) {
        return 'Пользователь включил уведомления о выигрыше';
    }

    if (preg_match('/^\/start(?:\s+.*)?$/iu', $text)) {
        return 'Пользователь запустил Telegram-бота';
    }

    if (preg_match('/^Нажал кнопку:\s*Участвовать в розыгрыше\s+[a-f0-9-]{20,}$/iu', $text)) {
        return 'Пользователь нажал кнопку «Участвовать»';
    }

    return $content;
}

$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
$platform = function_exists('normalize_platform') ? normalize_platform($_GET['platform'] ?? 'max') : ((($_GET['platform'] ?? 'max') === 'telegram') ? 'telegram' : 'max');
if (!$user_id) { 
    echo json_encode([]); 
    exit; 
}

try {
    $pdo = get_db_connection();
    ensure_platform_columns($pdo);

    // Сбрасываем непрочитанные сообщения только для выбранной площадки
    $stmt = $pdo->prepare("UPDATE users SET unread = 0 WHERE id = ? AND platform = ?");
    $stmt->execute([$user_id, $platform]);

    // Получаем историю сообщений только выбранной площадки
    $stmt = $pdo->prepare("
        SELECT direction AS dir, type, content, msg_id, created_at AS time, platform
        FROM messages 
        WHERE user_id = ? AND platform = ?
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([$user_id, $platform]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($platform === 'telegram' && $history) {
        foreach ($history as &$message) {
            if (($message['type'] ?? 'text') === 'text') {
                $message['content'] = normalize_telegram_admin_history_content($message['content'] ?? '');
            }
        }
        unset($message);
    }

    echo json_encode($history ?: []);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>