<?php
session_name('MAXRAFFLEBOT_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/projects/bots/MaxRaffleBot/admin/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/vk_api.php';

$pdo = get_db_connection();
ensure_platform_columns($pdo);

$result = null;
$resultTitle = '';

function vk_setup_safe($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vk_setup_result(string $title, array $data): void {
    global $result, $resultTitle;
    $resultTitle = $title;
    $result = $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_group') {
        vk_setup_result('Проверка VK-сообщества', vk_get_group_info());
    }

    if ($action === 'send_test') {
        $userId = trim($_POST['test_user_id'] ?? '');
        $text = trim($_POST['test_text'] ?? '');
        if ($text === '') {
            $text = 'Тестовое сообщение от VK-бота розыгрышей. Если ты видишь это в личке, отправка работает.';
        }
        if ($userId === '') {
            vk_setup_result('Тестовая отправка VK', ['success' => false, 'message' => 'Укажи VK user_id пользователя, который уже написал сообществу.']);
        } else {
            vk_setup_result('Тестовая отправка VK', vk_send_test_message($userId, $text));
        }
    }
}

$webhookUrl = rtrim(PROJECT_URL, '/') . '/vk_webhook.php';
$tokenFilled = defined('VK_GROUP_TOKEN') && trim((string)VK_GROUP_TOKEN) !== '';
$groupId = defined('VK_GROUP_ID') ? trim((string)VK_GROUP_ID) : '';
$confirmation = defined('VK_CONFIRMATION_CODE') ? trim((string)VK_CONFIRMATION_CODE) : '';
$secret = defined('VK_SECRET_KEY') ? trim((string)VK_SECRET_KEY) : '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VK setup</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; margin: 0; padding: 24px; }
        .wrap { max-width: 980px; margin: 0 auto; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 26px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        a { color: #2563eb; text-decoration: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
        .card { background: #fff; border-radius: 14px; padding: 18px; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); margin-bottom: 16px; }
        .muted { color: #6b7280; font-size: 14px; line-height: 1.5; }
        .status { padding: 12px 14px; border-radius: 12px; margin-bottom: 16px; background: #eef2ff; border: 1px solid #c7d2fe; }
        .warning { background: #fff7ed; border-color: #fed7aa; }
        .ok { background: #ecfdf5; border-color: #a7f3d0; }
        button { border: 0; border-radius: 10px; padding: 10px 14px; cursor: pointer; background: #2563eb; color: #fff; font-weight: 700; }
        input, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; font-size: 15px; }
        label { display: block; font-weight: 700; margin: 12px 0 6px; }
        pre { white-space: pre-wrap; word-break: break-word; background: #111827; color: #d1fae5; padding: 14px; border-radius: 12px; overflow: auto; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 6px; }
        ol { line-height: 1.65; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1>VK setup</h1>
        <a href="index.php">← Назад в админку</a>
    </div>

    <?php if (!$tokenFilled): ?>
        <div class="status warning">
            <b>VK_GROUP_TOKEN пустой.</b><br>
            Вставь токен сообщества в <code>config.php</code>, потом вернись на эту страницу.
        </div>
    <?php else: ?>
        <div class="status ok">
            <b>VK token заполнен.</b><br>
            Webhook URL: <code><?= vk_setup_safe($webhookUrl) ?></code><br>
            VK group ID: <code><?= $groupId !== '' ? vk_setup_safe($groupId) : 'не заполнен' ?></code>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="card">
            <h2><?= vk_setup_safe($resultTitle) ?></h2>
            <pre><?= vk_setup_safe(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h2>1. Проверка токена</h2>
            <p class="muted">Проверяет, что токен сообщества и ID группы работают.</p>
            <form method="POST">
                <input type="hidden" name="action" value="check_group">
                <button type="submit">Проверить VK-сообщество</button>
            </form>
        </div>

        <div class="card">
            <h2>2. Callback API</h2>
            <p class="muted">В настройках сообщества ВК добавь сервер Callback API.</p>
            <ol>
                <li>ВК → Управление сообществом → Работа с API → Callback API.</li>
                <li>URL сервера: <code><?= vk_setup_safe($webhookUrl) ?></code></li>
                <li>Строка подтверждения должна быть такой же, как <code>VK_CONFIRMATION_CODE</code> в config.php.</li>
                <li>Если используешь secret key, он должен совпадать с <code>VK_SECRET_KEY</code>.</li>
                <li>В типах событий включи <b>Входящие сообщения</b>.</li>
            </ol>
            <p class="muted">Сейчас confirmation code: <code><?= $confirmation !== '' ? vk_setup_safe($confirmation) : 'не заполнен' ?></code></p>
            <p class="muted">Secret key: <code><?= $secret !== '' ? 'заполнен' : 'не заполнен' ?></code></p>
        </div>

        <div class="card">
            <h2>3. Тест личного сообщения</h2>
            <p class="muted">Пользователь сначала должен написать сообществу. Потом укажи его VK ID и отправь тест.</p>
            <form method="POST">
                <input type="hidden" name="action" value="send_test">
                <label>VK user_id</label>
                <input type="text" name="test_user_id" placeholder="Например: 123456789 или vk_123456789">
                <label>Текст</label>
                <textarea name="test_text" rows="4">Тестовое сообщение от VK-бота розыгрышей.</textarea>
                <br><br>
                <button type="submit">Отправить тест</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Что должно заработать на этом этапе</h2>
        <p class="muted">Пользователь пишет в сообщения сообщества → событие приходит в <code>vk_webhook.php</code> → пользователь появляется в админке во вкладке <b>Чат → VK</b> → админ отвечает из админки → сообщение уходит пользователю в ВК.</p>
    </div>
</div>
</body>
</html>
