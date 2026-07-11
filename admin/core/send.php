<?php
require_once __DIR__ . "/session.php";
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/max_api.php';
require_once __DIR__ . '/../../lib/telegram_api.php';
require_once __DIR__ . '/../../lib/vk_api.php';
require_once __DIR__ . '/../../lib/chat.php';
require_once __DIR__ . '/../../config.php';

header('Content-Type: application/json');

function admin_chat_extract_max_message_id($res) {
    if (isset($res['message']['body']['mid'])) return $res['message']['body']['mid'];
    if (isset($res['message']['id'])) return $res['message']['id'];
    if (isset($res['message_id'])) return $res['message_id'];
    if (isset($res['data']['message_id'])) return $res['data']['message_id'];
    if (isset($res['id'])) return $res['id'];
    return null;
}

function admin_chat_extract_tg_message_id($res) {
    if (isset($res['result']['message_id'])) return (string)$res['result']['message_id'];
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? null;
    $platform = function_exists('normalize_platform') ? normalize_platform($_POST['platform'] ?? 'max') : ((($_POST['platform'] ?? 'max') === 'telegram') ? 'telegram' : 'max');
    $text = $_POST['text'] ?? '';
    $file = $_FILES['file'] ?? null;

    if (!$user_id) {
        echo json_encode(['success' => false, 'error' => 'No user_id']);
        exit;
    }

    $pdo = get_db_connection();
    ensure_platform_columns($pdo);

    $historyType = 'text';
    $historyContent = $text;
    $destPath = null;
    $webPath = null;
    $mime = null;

    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../../uploads/chat/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFileName = time() . '_' . rand(100, 999) . ($ext ? '.' . $ext : '');
        $destPath = $uploadDir . $newFileName;
        $webPath = PROJECT_URL . '/uploads/chat/' . $newFileName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $mime = mime_content_type($destPath) ?: 'application/octet-stream';

            if (strpos($mime, 'image/') === 0) {
                $historyType = 'photo';
            } else {
                $historyType = 'file';
            }

            $historyContent = $webPath;
        }
    }

    if ($platform === 'vk') {
        if ($webPath) {
            if ($destPath && file_exists($destPath)) {
                unlink($destPath);
            }
            echo json_encode(['success' => false, 'error' => 'На первом этапе VK-чат поддерживает только текстовые сообщения. Файлы добавим после проверки webhook и лички.']);
            exit;
        }
        if (trim($text) === '') {
            echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
            exit;
        }
        $res = vk_send_message($user_id, $text);
        if (!empty($res['success'])) {
            $msgId = isset($res['response']) ? (string)$res['response'] : null;
            save_chat_message($user_id, null, $text, 'out', 'text', null, $msgId, 'vk');
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
        }
        exit;
    }

    if ($platform === 'telegram') {
        if ($webPath) {
            if ($mime && strpos($mime, 'image/') === 0) {
                $res = telegram_send_photo($user_id, $webPath, $text);
            } elseif ($mime && strpos($mime, 'video/') === 0) {
                $res = telegram_send_video($user_id, $webPath, $text);
            } else {
                $res = telegram_send_document($user_id, $webPath, $text);
            }
        } else {
            if (trim($text) === '') {
                echo json_encode(['success' => false, 'error' => 'Пустое сообщение']);
                exit;
            }
            $res = telegram_send_message($user_id, $text);
        }

        if (!empty($res['ok'])) {
            $msgId = admin_chat_extract_tg_message_id($res);
            if ($webPath) {
                save_chat_message($user_id, null, $historyContent, 'out', $historyType, null, $msgId, 'telegram');
                if (trim($text) !== '') {
                    save_chat_message($user_id, null, $text, 'out', 'text', null, $msgId, 'telegram');
                }
            } else {
                save_chat_message($user_id, null, $text, 'out', 'text', null, $msgId, 'telegram');
            }
            echo json_encode(['success' => true]);
        } else {
            if ($destPath && file_exists($destPath)) {
                unlink($destPath);
            }
            echo json_encode(['success' => false, 'error' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
        }
        exit;
    }

    $attachments = [];
    if ($webPath && $destPath) {
        if (strpos((string)$mime, 'image/') === 0) {
            $apiType = 'image';
        } elseif (strpos((string)$mime, 'video/') === 0) {
            $apiType = 'video';
        } elseif (strpos((string)$mime, 'audio/') === 0) {
            $apiType = 'audio';
        } else {
            $apiType = 'file';
        }
        $attachments[] = max_prepare_attachment_from_file($destPath, $apiType);
    }

    $res = max_send_message($user_id, $text, !empty($attachments) ? $attachments : null, true);

    if (!isset($res['code']) && (!isset($res['success']) || $res['success'] !== false)) {
        $msgId = admin_chat_extract_max_message_id($res);

        if (!empty($attachments)) {
            save_chat_message($user_id, null, $historyContent, 'out', $historyType, null, $msgId, 'max');
        }

        if (!empty($text)) {
            save_chat_message($user_id, null, $text, 'out', 'text', null, $msgId, 'max');
        }

        echo json_encode(['success' => true]);
    } else {
        if ($destPath && file_exists($destPath)) {
            unlink($destPath);
        }

        echo json_encode(['success' => false, 'error' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
    }
}
?>
