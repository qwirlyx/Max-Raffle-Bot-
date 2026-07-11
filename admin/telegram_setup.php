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
require_once __DIR__ . '/../lib/telegram_api.php';

$pdo = get_db_connection();
ensure_platform_columns($pdo);
try { $pdo->exec("ALTER TABLE channels ADD COLUMN platform ENUM('max','telegram') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN channel_env ENUM('test','main') NOT NULL DEFAULT 'test' AFTER platform"); } catch (Exception $e) {}

$result = null;
$resultTitle = '';

function tg_setup_safe($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tg_setup_result(string $title, array $data): void {
    global $result, $resultTitle;
    $resultTitle = $title;
    $result = $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'check_bot') {
        tg_setup_result('Проверка бота getMe', telegram_get_me());
    }

    if ($action === 'set_webhook') {
        $webhookUrl = rtrim(PROJECT_URL, '/') . '/telegram_webhook.php';
        $secret = defined('TG_WEBHOOK_SECRET') ? trim((string)TG_WEBHOOK_SECRET) : '';
        tg_setup_result('Установка Telegram webhook', telegram_set_webhook($webhookUrl, $secret !== '' ? $secret : null));
    }

    if ($action === 'delete_webhook') {
        tg_setup_result('Удаление Telegram webhook', telegram_delete_webhook());
    }

    if ($action === 'webhook_info') {
        tg_setup_result('Информация о Telegram webhook', telegram_get_webhook_info());
    }

    if ($action === 'save_channel') {
        $rawChatInput = trim($_POST['chat_id'] ?? '');
        $chatInput = telegram_normalize_chat_input($rawChatInput);
        $channelEnv = ($_POST['channel_env'] ?? 'test') === 'main' ? 'main' : 'test';
        if ($chatInput === '') {
            tg_setup_result('Добавление Telegram-канала', ['success' => false, 'message' => 'Укажи @username канала, ссылку t.me или numeric chat_id']);
        } else {
            $chatRes = telegram_get_chat($chatInput);
            if (!empty($chatRes['ok']) && !empty($chatRes['result'])) {
                $chat = $chatRes['result'];
                $storedId = (string)($chat['id'] ?? $chatInput);
                $title = (string)($chat['title'] ?? $chat['username'] ?? $chatInput);
                $raw = [
                    'input' => $chatInput,
                    'raw_input' => $rawChatInput,
                    'telegram_chat' => $chat,
                    'channel_env' => $channelEnv,
                ];
                $stmt = $pdo->prepare("
                    INSERT INTO channels (id, platform, channel_env, title, raw_data)
                    VALUES (?, 'telegram', ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        platform = 'telegram',
                        channel_env = VALUES(channel_env),
                        title = VALUES(title),
                        raw_data = VALUES(raw_data)
                ");
                $stmt->execute([$storedId, $channelEnv, $title, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                tg_setup_result('Добавление Telegram-канала', [
                    'success' => true,
                    'message' => 'Канал добавлен в базу. Теперь он должен появиться в создании розыгрыша при выборе Telegram.',
                    'saved_channel_id' => $storedId,
                    'title' => $title,
                    'channel_env' => $channelEnv,
                    'telegram_response' => $chatRes,
                ]);
            } else {
                tg_setup_result('Добавление Telegram-канала', $chatRes + [
                    'hint' => 'Проверь, что бот добавлен администратором канала. Можно указать @username, ссылку https://t.me/... или numeric chat_id. Для приватного канала нужен numeric chat_id.',
                ]);
            }
        }
    }

    if ($action === 'send_test') {
        $chatId = trim($_POST['test_chat_id'] ?? '');
        $text = trim($_POST['test_text'] ?? '');
        if ($text === '') {
            $text = "Тестовое сообщение от бота розыгрышей. Если ты видишь это в канале, подключение Telegram работает.";
        }
        if ($chatId === '') {
            tg_setup_result('Тестовая отправка в канал', ['success' => false, 'message' => 'Выбери канал или укажи chat_id']);
        } else {
            tg_setup_result('Тестовая отправка в канал', telegram_send_test_message($chatId, $text));
        }
    }


    if ($action === 'verify_channel') {
        $chatId = trim($_POST['channel_id'] ?? '');
        if ($chatId === '') {
            tg_setup_result('Проверка Telegram-канала', ['success' => false, 'message' => 'Канал не выбран']);
        } else {
            $chatRes = telegram_get_chat($chatId);
            $adminRes = telegram_check_bot_admin($chatId);
            tg_setup_result('Проверка Telegram-канала', [
                'success' => !empty($chatRes['ok']) && !empty($adminRes['ok']) && !empty($adminRes['is_admin']),
                'chat_id' => $chatId,
                'chat' => $chatRes,
                'bot_admin_check' => $adminRes,
                'hint' => 'Для публикации и проверки подписки бот должен быть администратором канала.',
            ]);
        }
    }

    if ($action === 'update_channel_env') {
        $chatId = trim($_POST['channel_id'] ?? '');
        $channelEnv = ($_POST['channel_env'] ?? 'test') === 'main' ? 'main' : 'test';
        if ($chatId === '') {
            tg_setup_result('Изменение типа Telegram-канала', ['success' => false, 'message' => 'Канал не выбран']);
        } else {
            $stmt = $pdo->prepare("UPDATE channels SET channel_env = ? WHERE id = ? AND platform = 'telegram'");
            $stmt->execute([$channelEnv, $chatId]);
            tg_setup_result('Изменение типа Telegram-канала', [
                'success' => true,
                'message' => $channelEnv === 'main' ? 'Канал отмечен как основной.' : 'Канал отмечен как тестовый.',
                'channel_id' => $chatId,
                'channel_env' => $channelEnv,
            ]);
        }
    }

    if ($action === 'delete_channel') {
        $chatId = trim($_POST['channel_id'] ?? '');
        if ($chatId === '') {
            tg_setup_result('Удаление Telegram-канала', ['success' => false, 'message' => 'Канал не выбран']);
        } else {
            $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM raffles WHERE channel_id = ? AND platform = 'telegram' AND status IN ('pending','active','processing')");
            $stmtActive->execute([$chatId]);
            $activeCount = (int)$stmtActive->fetchColumn();

            if ($activeCount > 0) {
                tg_setup_result('Удаление Telegram-канала', [
                    'success' => false,
                    'message' => 'Канал нельзя удалить из сервиса, пока по нему есть активные/запланированные Telegram-розыгрыши.',
                    'active_raffles' => $activeCount,
                ]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM channels WHERE id = ? AND platform = 'telegram'");
                $stmt->execute([$chatId]);
                tg_setup_result('Удаление Telegram-канала', [
                    'success' => true,
                    'message' => 'Канал удалён из сервиса. В самом Telegram ничего не удалялось.',
                    'deleted_channel_id' => $chatId,
                ]);
            }
        }
    }
}

$telegramChannels = [];
try {
    $telegramChannels = $pdo->query("SELECT id, title, raw_data, channel_env FROM channels WHERE platform = 'telegram' ORDER BY channel_env ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $telegramChannels = [];
}

$webhookUrl = rtrim(PROJECT_URL, '/') . '/telegram_webhook.php';
$tokenFilled = defined('TG_BOT_TOKEN') && trim((string)TG_BOT_TOKEN) !== '';
$username = defined('TG_BOT_USERNAME') ? trim((string)TG_BOT_USERNAME) : '';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telegram setup</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f8; color: #1f2937; margin: 0; padding: 24px; }
        .wrap { max-width: 980px; margin: 0 auto; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
        h1 { margin: 0; font-size: 26px; }
        h2 { margin: 0 0 12px; font-size: 18px; }
        a { color: #2563eb; text-decoration: none; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
        .card { background: #fff; border-radius: 14px; padding: 18px; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .muted { color: #6b7280; font-size: 14px; line-height: 1.5; }
        .status { padding: 12px 14px; border-radius: 12px; margin-bottom: 16px; background: #eef2ff; border: 1px solid #c7d2fe; }
        .warning { background: #fff7ed; border-color: #fed7aa; }
        .ok { background: #ecfdf5; border-color: #a7f3d0; }
        .danger { background: #fef2f2; border-color: #fecaca; }
        button { border: 0; border-radius: 10px; padding: 10px 14px; cursor: pointer; background: #2563eb; color: #fff; font-weight: 700; }
        button.secondary { background: #475569; }
        button.danger { background: #dc2626; }
        input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 10px; padding: 10px 12px; font-size: 15px; }
        label { display: block; font-weight: 700; margin: 12px 0 6px; }
        form { margin: 0; }
        pre { white-space: pre-wrap; word-break: break-word; background: #111827; color: #d1fae5; padding: 14px; border-radius: 12px; overflow: auto; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 6px; }
        .actions { display: flex; flex-wrap: wrap; gap: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; border-bottom: 1px solid #e5e7eb; padding: 10px 8px; vertical-align: top; }
        th { color: #475569; font-size: 13px; }
        .inline-form { display:inline-block; margin:0 4px 4px 0; }
        .env-badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; font-weight:700; }
        .env-test { background:#e0f2fe; color:#0369a1; }
        .env-main { background:#ffedd5; color:#9a3412; }
        .main-warning { background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px 12px; color:#9a3412; font-size:14px; line-height:1.45; margin-top:10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h1>Telegram setup</h1>
        <a href="index.php">← Назад в админку</a>
    </div>

    <?php if (!$tokenFilled): ?>
        <div class="status warning">
            <b>TG_BOT_TOKEN пустой.</b><br>
            Сначала создай бота в BotFather и заполни <code>TG_BOT_TOKEN</code> в <code>config.php</code>.
        </div>
    <?php else: ?>
        <div class="status ok">
            <b>Telegram token заполнен.</b><br>
            Webhook URL для этого проекта: <code><?= tg_setup_safe($webhookUrl) ?></code>
            <?php if ($username !== ''): ?><br>Bot username: <code>@<?= tg_setup_safe($username) ?></code><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="card" style="margin-bottom:16px;">
            <h2><?= tg_setup_safe($resultTitle) ?></h2>
            <pre><?= tg_setup_safe(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h2>1. Проверка бота</h2>
            <p class="muted">Кнопка вызывает Telegram getMe. Если токен правильный, вернётся имя и username бота.</p>
            <form method="post">
                <input type="hidden" name="action" value="check_bot">
                <button type="submit">Проверить бота</button>
            </form>
        </div>

        <div class="card">
            <h2>2. Webhook</h2>
            <p class="muted">Webhook нужен, чтобы Telegram отправлял события на сайт: нажатия кнопок, сообщения, старт бота.</p>
            <div class="actions">
                <form method="post"><input type="hidden" name="action" value="set_webhook"><button type="submit">Установить webhook</button></form>
                <form method="post"><input type="hidden" name="action" value="webhook_info"><button class="secondary" type="submit">Проверить webhook</button></form>
                <form method="post"><input type="hidden" name="action" value="delete_webhook"><button class="danger" type="submit">Удалить webhook</button></form>
            </div>
        </div>

        <div class="card">
            <h2>3. Добавить Telegram-канал</h2>
            <p class="muted">Для первой проверки проще сделать канал публичным и указать его username в формате <code>@channel_username</code>. Бот должен быть администратором канала.</p>
            <form method="post">
                <input type="hidden" name="action" value="save_channel">
                <label for="chat_id">@username, ссылка t.me или numeric chat_id</label>
                <input id="chat_id" name="chat_id" placeholder="@my_raffle_channel">
                <label for="channel_env">Тип канала</label>
                <select id="channel_env" name="channel_env">
                    <option value="test" selected>Тестовый</option>
                    <option value="main">Основной</option>
                </select>
                <div class="main-warning">Основной канал используй только для боевого Telegram-канала. При создании розыгрыша в основной канал админка попросит отдельное подтверждение.</div>
                <div style="margin-top:12px;"><button type="submit">Добавить канал в сервис</button></div>
            </form>
        </div>

        <div class="card">
            <h2>4. Тестовая отправка</h2>
            <p class="muted">После добавления канала отправь тестовое сообщение. Если оно появилось в канале — бот подключен правильно.</p>
            <form method="post">
                <input type="hidden" name="action" value="send_test">
                <label for="test_chat_id">Канал</label>
                <select id="test_chat_id" name="test_chat_id">
                    <option value="">Выбери канал</option>
                    <?php foreach ($telegramChannels as $channel): ?>
                        <?php $envLabel = (($channel['channel_env'] ?? 'test') === 'main') ? 'Основной' : 'Тестовый'; ?>
                        <option value="<?= tg_setup_safe($channel['id']) ?>">[<?= tg_setup_safe($envLabel) ?>] <?= tg_setup_safe($channel['title']) ?> — <?= tg_setup_safe($channel['id']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="test_text">Текст</label>
                <textarea id="test_text" name="test_text" rows="4">Тестовое сообщение от бота розыгрышей. Если ты видишь это в канале, подключение Telegram работает.</textarea>
                <div style="margin-top:12px;"><button type="submit">Отправить тест</button></div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h2>5. Подключённые Telegram-каналы</h2>
        <?php if (empty($telegramChannels)): ?>
            <p class="muted">Пока нет подключённых Telegram-каналов.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Канал</th>
                        <th>Тип</th>
                        <th>Chat ID</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($telegramChannels as $channel): ?>
                    <tr>
                        <?php $channelEnv = (($channel['channel_env'] ?? 'test') === 'main') ? 'main' : 'test'; ?>
                        <td><b><?= tg_setup_safe($channel['title']) ?></b></td>
                        <td>
                            <span class="env-badge <?= $channelEnv === 'main' ? 'env-main' : 'env-test' ?>"><?= $channelEnv === 'main' ? 'Основной' : 'Тестовый' ?></span>
                            <form method="post" style="margin-top:8px; max-width:180px;">
                                <input type="hidden" name="action" value="update_channel_env">
                                <input type="hidden" name="channel_id" value="<?= tg_setup_safe($channel['id']) ?>">
                                <select name="channel_env" style="margin-bottom:6px;">
                                    <option value="test" <?= $channelEnv === 'test' ? 'selected' : '' ?>>Тестовый</option>
                                    <option value="main" <?= $channelEnv === 'main' ? 'selected' : '' ?>>Основной</option>
                                </select>
                                <button class="secondary" type="submit">Сохранить тип</button>
                            </form>
                        </td>
                        <td><code><?= tg_setup_safe($channel['id']) ?></code></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="verify_channel">
                                <input type="hidden" name="channel_id" value="<?= tg_setup_safe($channel['id']) ?>">
                                <button class="secondary" type="submit">Проверить права</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Удалить канал только из сервиса? В Telegram канал не удалится.');">
                                <input type="hidden" name="action" value="delete_channel">
                                <input type="hidden" name="channel_id" value="<?= tg_setup_safe($channel['id']) ?>">
                                <button class="danger" type="submit">Удалить из сервиса</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:16px;">
        <h2>Что должно получиться</h2>
        <p class="muted">После успешного добавления канал появится в обычной форме создания розыгрыша, когда выбрана площадка Telegram. Telegram-розыгрыши, рассылки, чат и уведомления можно проверять на тестовом канале до подключения основного канала.</p>
    </div>
</div>
</body>
</html>
