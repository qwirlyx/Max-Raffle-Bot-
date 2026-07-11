<?php
require_once __DIR__ . "/session.php";
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/max_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit;
}

$action = $_POST['action'] ?? '';
$userId = $_POST['user_id'] ?? '';
$msgTime = (int)($_POST['time'] ?? 0);
$newText = $_POST['text'] ?? '';
$msgId = $_POST['msg_id'] ?? '';
$oldSearchText = $_POST['search_text'] ?? '';
$platform = ($_POST['platform'] ?? 'max') === 'telegram' ? 'telegram' : 'max'; 

if (!$userId || !$action || !$msgTime) {
    echo json_encode(['success' => false, 'error' => 'Отсутствуют обязательные параметры']);
    exit;
}

try {
    $pdo = get_db_connection();
    ensure_platform_columns($pdo);

    if ($platform !== 'max') {
        echo json_encode(['success' => false, 'error' => 'Редактирование и удаление через API сейчас доступно только для MAX.']);
        exit;
    }

    // Если нет msgId, пробуем найти его в базе по времени
    if (empty($msgId)) {
        $stmt = $pdo->prepare("SELECT msg_id FROM messages WHERE user_id = ? AND platform = 'max' AND created_at = ? AND direction = 'out' LIMIT 1");
        $stmt->execute([$userId, $msgTime]);
        $localMsg = $stmt->fetch();
        if ($localMsg && !empty($localMsg['msg_id'])) {
            $msgId = $localMsg['msg_id'];
        }
    }

    // Если всё ещё нет msgId, ищем через API (умный поиск по тексту)
    if (empty($msgId) && !empty($oldSearchText)) {
        $historyRes = max_get_messages($userId, 100);
        
        if (isset($historyRes['code']) && $historyRes['code'] === 'chat.not.found') {
            $url = MAX_BOT_API_BASE_URL . 'messages?user_id=' . urlencode($userId) . '&count=100';
            $historyRes = max_request($url, [], 'GET');
        }

        if (isset($historyRes['messages']) && is_array($historyRes['messages'])) {
            $cleanSearch = trim(str_replace(["\r", "\n", " ", "\t"], "", mb_substr($oldSearchText, 0, 50)));
            foreach ($historyRes['messages'] as $msg) {
                $rawText = $msg['text'] ?? ($msg['body']['text'] ?? '');
                $cleanMsg = trim(str_replace(["\r", "\n", " ", "\t"], "", $rawText));
                if (!empty($cleanMsg) && mb_strpos($cleanMsg, $cleanSearch) !== false) {
                    $msgId = $msg['id'] ?? ($msg['body']['mid'] ?? null);
                    if ($msgId) break;
                }
            }
        }
    }

    if (empty($msgId)) {
        echo json_encode(['success' => false, 'error' => 'Не удалось найти ID сообщения в истории чата MAX']);
        exit;
    }

    // ВЫПОЛНЕНИЕ ДЕЙСТВИЯ
    if ($action === 'delete') {
        $res = max_delete_message($msgId);
        
        if (isset($res['success']) && $res['success'] === false) {
            echo json_encode(['success' => false, 'error' => $res['message'] ?? 'Неизвестная ошибка API (> 24 часов?)']);
        } else {
            // Удаляем из базы
            $stmt = $pdo->prepare("DELETE FROM messages WHERE user_id = ? AND platform = 'max' AND (msg_id = ? OR created_at = ?)");
            $stmt->execute([$userId, $msgId, $msgTime]);
            echo json_encode(['success' => true]);
        }

    } elseif ($action === 'edit') {
        $res = max_edit_message($msgId, $newText);
        
        if (isset($res['success']) && $res['success'] === false) {
            echo json_encode(['success' => false, 'error' => $res['message'] ?? 'Неизвестная ошибка API (> 24 часов?)']);
        } else {
            // Обновляем в базе
            $stmt = $pdo->prepare("UPDATE messages SET content = ?, msg_id = ? WHERE user_id = ? AND platform = 'max' AND (msg_id = ? OR created_at = ?)");
            $stmt->execute([$newText, $msgId, $userId, $msgId, $msgTime]);
            echo json_encode(['success' => true]);
        }
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка БД: ' . $e->getMessage()]);
}
?>