<?php
session_name('MAXRAFFLEBOT_SESSID');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/projects/bots/MaxRaffleBot/admin/',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// --- АВТОРИЗАЦИЯ ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: login.php');
    exit;
}
// -------------------

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/max_api.php';
require_once __DIR__ . '/../lib/telegram_api.php';
require_once __DIR__ . '/../lib/vk_api.php';
require_once __DIR__ . '/../lib/google_sheets.php';
require_once __DIR__ . '/../lib/promocode_import.php';
require_once __DIR__ . '/../lib/broadcasts.php';

$pdo = get_db_connection();
ensure_platform_columns($pdo);
ensure_vk_group_channel($pdo);
ensure_broadcast_tables($pdo);

// АВТОМАТИЧЕСКАЯ НАСТРОЙКА БД
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN text TEXT AFTER title"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN media_url VARCHAR(255) AFTER text"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN start_date DATETIME AFTER media_url"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN end_date DATETIME AFTER start_date"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN winners_count INT DEFAULT 1 AFTER end_date"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN post_message_id VARCHAR(255) AFTER status"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN notify_non_winners TINYINT(1) NOT NULL DEFAULT 0 AFTER check_subscription"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN media_payload LONGTEXT NULL AFTER media_type"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN scheduled_at DATETIME NULL AFTER media_payload"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN started_at DATETIME NULL AFTER failed_count"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN finished_at DATETIME NULL AFTER started_at"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE channels MODIFY COLUMN id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles MODIFY COLUMN channel_id VARCHAR(64) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffle_promocodes MODIFY COLUMN assigned_to_user_id VARCHAR(64) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE broadcast_recipients MODIFY COLUMN user_id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE channels ADD COLUMN channel_env ENUM('test','main') NOT NULL DEFAULT 'test' AFTER platform"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE messages ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER user_id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_like TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_non_winners"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_comment TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_require_like"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_comment_word VARCHAR(255) NULL AFTER vk_require_comment"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_case_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_comment_word"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_bot_message TINYINT(1) NOT NULL DEFAULT 1 AFTER vk_case_sensitive"); } catch (Exception $e) {}

$activeTab = $_GET['tab'] ?? 'Create';
$activeChatId = $_GET['chat_id'] ?? null;
$activeChatPlatform = normalize_platform($_GET['chat_platform'] ?? ($_SESSION['chat_platform'] ?? 'max'));
$_SESSION['chat_platform'] = $activeChatPlatform;
if ($activeChatId) $activeTab = 'Chat'; 

$statusMsg = '';

if (isset($_GET['status'])) {
    if ($_GET['status'] === 'settings_saved') $statusMsg = '✅ Настройки сохранены!';
    if ($_GET['status'] === 'raffle_created') $statusMsg = '🚀 Розыгрыш успешно создан!';
    if ($_GET['status'] === 'deleted') $statusMsg = '🗑 Розыгрыш удален из БД.';
    if ($_GET['status'] === 'post_deleted') $statusMsg = '🗑 Пост успешно удален из канала!';
    if ($_GET['status'] === 'edited') $statusMsg = '✏️ Пост отредактирован!';
    if ($_GET['status'] === 'error_upload') $statusMsg = '❌ Ошибка загрузки файла!';
    if ($_GET['status'] === 'broadcast_created') $statusMsg = '📣 Рассылка создана!';
    if ($_GET['status'] === 'broadcast_deleted') $statusMsg = '🗑 Рассылка удалена.';
    if ($_GET['status'] === 'broadcast_run_now') $statusMsg = '▶ Рассылка переведена на немедленный запуск.';
}
if (isset($_GET['error'])) {
    $statusMsg = '❌ ' . htmlspecialchars($_GET['error']);
}

$settingsRes = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$settings = [
    'winner_template' => $settingsRes['winner_template'] ?? "Поздравляем! 🎈 Ты выиграл в розыгрыше: {title}!\n\nТвой промокод:\n{promocode}\n\nСкоро с тобой свяжется менеджер.",
    'winner_post_template' => $settingsRes['winner_post_template'] ?? "🏆 Итоги: {title}\n\nПоздравляем победителей:\n{winners}",
    'non_winner_template' => $settingsRes['non_winner_template'] ?? "Спасибо за участие в розыгрыше {title}. В этот раз удача улыбнулась другим, но впереди ещё будут новые розыгрыши 🍀",
    'vk_post_footer_template' => $settingsRes['vk_post_footer_template'] ?? "{comment_instruction}\n{like_instruction}\n{subscription_instruction}\n{bot_instruction}\n\nПобедитель получит промокод в личные сообщения сообщества.",
    'vk_winner_post_template' => $settingsRes['vk_winner_post_template'] ?? "🏆 Итоги: {title}\n\nПоздравляем победителей:\n{winners}",
    'vk_no_participants_template' => $settingsRes['vk_no_participants_template'] ?? "😔 В розыгрыше {title} нет участников, которые выполнили условия.",
    'vk_winner_template' => $settingsRes['vk_winner_template'] ?? "Поздравляем! 🎈 Ты выиграл в розыгрыше: {title}!\n\nТвой промокод:\n{promocode}",
    'vk_non_winner_template' => $settingsRes['vk_non_winner_template'] ?? "Спасибо за участие в розыгрыше {title}. В этот раз удача улыбнулась другим, но впереди ещё будут новые розыгрыши 🍀"
];

function find_message_id_by_text($channel_id, $search_text) {
    if (empty($search_text)) return null;
    $historyRes = max_get_messages($channel_id, 100);
    if (isset($historyRes['messages']) && is_array($historyRes['messages'])) {
        $cleanSearch = trim(str_replace(["\r", "\n", " ", "\t"], "", mb_substr($search_text, 0, 50)));
        foreach ($historyRes['messages'] as $msg) {
            $rawText = $msg['text'] ?? ($msg['body']['text'] ?? '');
            $cleanMsg = trim(str_replace(["\r", "\n", " ", "\t"], "", $rawText));
            if (!empty($cleanMsg) && mb_strpos($cleanMsg, $cleanSearch) !== false) {
                return $msg['id'] ?? ($msg['body']['mid'] ?? null);
            }
        }
    }
    return null;
}

function admin_tg_raffle_deep_link(string $payload): string {
    $username = defined('TG_BOT_USERNAME') ? trim((string)TG_BOT_USERNAME) : '';
    if ($username === '') return '';
    return 'https://t.me/' . ltrim($username, '@') . '?start=' . rawurlencode($payload);
}

function admin_tg_raffle_keyboard(string $raffleId): array {
    $rows = [[['text' => 'Участвовать 🎁', 'callback_data' => 'tg_participate_' . $raffleId]]];
    $notifyUrl = admin_tg_raffle_deep_link('notify_' . $raffleId);
    if ($notifyUrl !== '') {
        $rows[] = [['text' => 'Включить уведомления о выигрыше 🔔', 'url' => $notifyUrl]];
    }
    return telegram_inline_keyboard($rows);
}

function admin_tg_media_type_from_url(?string $mediaUrl): string {
    if (!$mediaUrl) return 'file';
    $path = parse_url($mediaUrl, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
    if (in_array($ext, ['mp4', 'mov', 'm4v', 'webm'], true)) return 'video';
    return 'file';
}

function admin_tg_edit_raffle_post(array $raffle, string $newText): array {
    $msgId = $raffle['post_message_id'] ?? null;
    if (!$msgId) {
        return ['ok' => false, 'success' => false, 'description' => 'Telegram message_id не найден в базе'];
    }

    $keyboard = admin_tg_raffle_keyboard((string)$raffle['id']);
    $mediaUrl = trim((string)($raffle['media_url'] ?? ''));
    if ($mediaUrl !== '' && mb_strlen($newText, 'UTF-8') <= 900) {
        $mediaType = admin_tg_media_type_from_url($mediaUrl);
        if ($mediaType === 'image' || $mediaType === 'video') {
            return telegram_edit_message_caption($raffle['channel_id'], $msgId, $newText, $keyboard);
        }
    }

    return telegram_edit_message_text($raffle['channel_id'], $msgId, $newText, $keyboard);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'save_settings') {
        $stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute(['winner_template', $_POST['winner_template'] ?? '']);
        $stmt->execute(['winner_post_template', $_POST['winner_post_template'] ?? '']);
        $stmt->execute(['non_winner_template', $_POST['non_winner_template'] ?? '']);
        $stmt->execute(['vk_post_footer_template', $_POST['vk_post_footer_template'] ?? '']);
        $stmt->execute(['vk_winner_post_template', $_POST['vk_winner_post_template'] ?? '']);
        $stmt->execute(['vk_no_participants_template', $_POST['vk_no_participants_template'] ?? '']);
        $stmt->execute(['vk_winner_template', $_POST['vk_winner_template'] ?? '']);
        $stmt->execute(['vk_non_winner_template', $_POST['vk_non_winner_template'] ?? '']);
        header("Location: index.php?tab=Settings&status=settings_saved");
        exit;
    }

    if ($_POST['action'] === 'create') {
        $mediaUrl = '';
        if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileTmpPath = $_FILES['media_file']['tmp_name'];
            $fileName = (string)($_FILES['media_file']['name'] ?? 'media.bin');
            $newFileName = make_safe_uploaded_filename('raffle', $fileName, $fileTmpPath);
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $mediaUrl = build_project_file_url(PROJECT_URL, '/uploads/' . $newFileName);
            } else {
                header("Location: index.php?tab=Create&status=error_upload");
                exit;
            }
        }

        $platform = normalize_platform($_POST['platform'] ?? 'max');
        $channelId = trim((string)($_POST['channel_id'] ?? ''));
        $channelEnv = 'test';

        if ($platform === 'vk') {
            $vkGroupId = defined('VK_GROUP_ID') ? ltrim(trim((string)VK_GROUP_ID), '-') : '';
            if ($vkGroupId === '') {
                header("Location: index.php?tab=Create&error=" . urlencode('VK_GROUP_ID не заполнен в config.php. Нельзя создать VK-розыгрыш.'));
                exit;
            }
            ensure_vk_group_channel($pdo);
            $channelId = 'vk_' . $vkGroupId;
        } else {
            $stmtChannelCheck = $pdo->prepare("SELECT channel_env FROM channels WHERE id = ? AND platform = ? LIMIT 1");
            $stmtChannelCheck->execute([$channelId, $platform]);
            $channelEnv = $stmtChannelCheck->fetchColumn();
            if ($channelEnv === false) {
                header("Location: index.php?tab=Create&error=" . urlencode('Выбранный канал не относится к площадке ' . strtoupper($platform)));
                exit;
            }
            $channelEnv = $channelEnv === 'main' ? 'main' : 'test';
            if ($platform === 'telegram' && $channelEnv === 'main' && empty($_POST['confirm_main_channel'])) {
                header("Location: index.php?tab=Create&error=" . urlencode('Выбран основной Telegram-канал. Подтверди публикацию галочкой, чтобы случайно не отправить тест подписчикам.'));
                exit;
            }
        }

        $id = generate_uuid();
        $stmt = $pdo->prepare("
            INSERT INTO raffles (id, platform, title, text, media_url, start_date, end_date, winners_count, channel_id, check_subscription, notify_non_winners, vk_require_like, vk_require_comment, vk_comment_mode, vk_comment_word, vk_number_min, vk_number_max, vk_case_sensitive, vk_require_bot_message, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([
            $id,
            $platform,
            $_POST['title'],
            $_POST['text'],
            $mediaUrl,
            str_replace('T', ' ', $_POST['start_date']),
            str_replace('T', ' ', $_POST['end_date']),
            (int)$_POST['winners_count'],
            $channelId,
            isset($_POST['check_subscription']) ? 1 : 0,
            isset($_POST['notify_non_winners']) ? 1 : 0,
            isset($_POST['vk_require_like']) ? 1 : 0,
            isset($_POST['vk_require_comment']) ? 1 : 0,
            (($_POST['vk_comment_mode'] ?? 'word') === 'number') ? 'number' : 'word',
            trim((string)($_POST['vk_comment_word'] ?? '')),
            ($_POST['vk_number_min'] ?? '') !== '' ? (int)$_POST['vk_number_min'] : null,
            ($_POST['vk_number_max'] ?? '') !== '' ? (int)$_POST['vk_number_max'] : null,
            isset($_POST['vk_case_sensitive']) ? 1 : 0,
            isset($_POST['vk_require_bot_message']) ? 1 : 0
        ]);
        
        $allCodes = [];
        
        // 1. Промокоды из textarea
        if (!empty($_POST['create_codes_text'])) {
            $allCodes = array_merge($allCodes, normalize_codes_from_text($_POST['create_codes_text']));
        }
        
        // 2. Промокоды из файла
        if (isset($_FILES['create_codes_file']) && $_FILES['create_codes_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['create_codes_file']['tmp_name'];
            $originalName = $_FILES['create_codes_file']['name'] ?? '';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
            if ($ext === 'txt') {
                $fileText = file_get_contents($tmpPath);
                $allCodes = array_merge($allCodes, normalize_codes_from_text($fileText));
            } elseif ($ext === 'csv') {
                $allCodes = array_merge($allCodes, read_codes_from_csv_file($tmpPath, ','));
            } elseif ($ext === 'xlsx') {
                $allCodes = array_merge($allCodes, read_codes_from_xlsx_file($tmpPath));
            }
        }
        
        // 3. Промокоды из Google Таблицы
        $googleSheetUrl = trim($_POST['create_google_sheet_url'] ?? '');
        $googleSheetName = trim($_POST['create_google_sheet_name'] ?? '');
        
        if ($googleSheetUrl !== '' && $googleSheetName !== '') {
            try {
                $sheetCodes = gs_read_promocodes($googleSheetUrl, $googleSheetName);
                $allCodes = array_merge($allCodes, $sheetCodes);
            } catch (Throwable $e) {
                header("Location: index.php?tab=Create&error=" . urlencode('Ошибка Google Sheets: ' . $e->getMessage()));
                exit;
            }
        }
        
        // 4. Сохраняем промокоды в raffle_promocodes
        $allCodes = array_values(array_unique($allCodes));
        
        if (!empty($allCodes)) {
            save_promocodes_to_raffle($pdo, $id, $allCodes);
        }
        
        header("Location: index.php?tab=List&status=raffle_created");
        exit;
        
    }

    if ($_POST['action'] === 'create_broadcast') {
        $broadcastPlatform = normalize_platform($_POST['broadcast_platform'] ?? 'max');
        $title = trim($_POST['broadcast_title'] ?? '');
        $filterType = trim($_POST['broadcast_filter_type'] ?? '');
        $raffleId = trim($_POST['broadcast_raffle_id'] ?? '');
        $messageText = trim($_POST['broadcast_message_text'] ?? '');
        $scheduledAtRaw = trim($_POST['broadcast_scheduled_at'] ?? '');
        $scheduledAtDb = $scheduledAtRaw !== '' ? str_replace('T', ' ', $scheduledAtRaw) : null;

        if ($title === '' || $messageText === '') {
            header("Location: index.php?tab=Broadcasts&error=" . urlencode('Заполни название и текст рассылки.'));
            exit;
        }

        $allowedFilters = ['all_users', 'raffle_participants', 'raffle_winners', 'raffle_non_winners'];
        if (!in_array($filterType, $allowedFilters, true)) {
            header("Location: index.php?tab=Broadcasts&error=" . urlencode('Неизвестный фильтр аудитории.'));
            exit;
        }

        if ($filterType !== 'all_users' && $raffleId === '') {
            header("Location: index.php?tab=Broadcasts&error=" . urlencode('Для выбранного фильтра нужно указать розыгрыш.'));
            exit;
        }

        if ($raffleId !== '') {
            $stmtRafflePlatform = $pdo->prepare("SELECT platform FROM raffles WHERE id = ? LIMIT 1");
            $stmtRafflePlatform->execute([$raffleId]);
            $rafflePlatform = $stmtRafflePlatform->fetchColumn();
            if ($rafflePlatform !== $broadcastPlatform) {
                header("Location: index.php?tab=Broadcasts&error=" . urlencode('Выбранный розыгрыш относится к другой площадке.'));
                exit;
            }
        }

        try {
            $media = save_broadcast_media($_FILES['broadcast_media_file'] ?? [], PROJECT_URL, $broadcastPlatform);
            $recipientIds = get_broadcast_recipient_ids($pdo, $filterType, $raffleId !== '' ? $raffleId : null, $broadcastPlatform);
            $broadcastId = generate_uuid();
            create_broadcast_with_queue(
                $pdo,
                $broadcastId,
                $title,
                $filterType,
                $raffleId !== '' ? $raffleId : null,
                $messageText,
                $media['media_url'],
                $media['media_type'],
                $media['media_payload'] ?? null,
                $scheduledAtDb,
                $recipientIds,
                $broadcastPlatform
            );
            header("Location: index.php?tab=Broadcasts&status=broadcast_created");
            exit;
        } catch (Throwable $e) {
            header("Location: index.php?tab=Broadcasts&error=" . urlencode($e->getMessage()));
            exit;
        }
    }

    if ($_POST['action'] === 'run_broadcast_now') {
        $broadcastId = $_POST['broadcast_id'] ?? '';
        if ($broadcastId !== '') {
            $stmt = $pdo->prepare("UPDATE broadcasts SET status = 'pending', scheduled_at = NOW(), finished_at = NULL WHERE id = ?");
            $stmt->execute([$broadcastId]);
        }
        header("Location: index.php?tab=Broadcasts&status=broadcast_run_now");
        exit;
    }

    if ($_POST['action'] === 'delete_broadcast') {
        $broadcastId = $_POST['broadcast_id'] ?? '';
        if ($broadcastId !== '') {
            $stmt = $pdo->prepare("SELECT media_url FROM broadcasts WHERE id = ?");
            $stmt->execute([$broadcastId]);
            $mediaUrl = $stmt->fetchColumn();

            $pdo->prepare("DELETE FROM broadcast_recipients WHERE broadcast_id = ?")->execute([$broadcastId]);
            $pdo->prepare("DELETE FROM broadcasts WHERE id = ?")->execute([$broadcastId]);

            $fullPath = project_media_url_to_local_path($mediaUrl ?: null, PROJECT_URL);
            if ($fullPath && strpos($fullPath, realpath(__DIR__ . '/../uploads/broadcasts')) === 0) {
                @unlink($fullPath);
            }
        }
        header("Location: index.php?tab=Broadcasts&status=broadcast_deleted");
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM raffles WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        header("Location: index.php?tab=List&status=deleted");
        exit;
    }

    if ($_POST['action'] === 'delete_post') {
        $id = $_POST['id'];
        $stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();

        if ($r) {
            $platform = ($r['platform'] ?? 'max') === 'telegram' ? 'telegram' : 'max';
            $msgId = $r['post_message_id'] ?: ($platform === 'max' ? find_message_id_by_text($r['channel_id'], $r['text']) : null);
            if ($msgId) {
                if ($platform === 'telegram') {
                    $res = telegram_delete_message($r['channel_id'], $msgId);
                    $ok = !empty($res['ok']);
                    $errMsg = $res['description'] ?? $res['message'] ?? 'Unknown Telegram error';
                } else {
                    $res = max_delete_message($msgId);
                    $ok = isset($res['success']) && $res['success'] === true;
                    $errMsg = $res['message'] ?? 'Unknown';
                }

                if ($ok) {
                    $pdo->prepare("UPDATE raffles SET post_message_id = NULL WHERE id = ?")->execute([$id]);
                    header("Location: index.php?tab=List&status=post_deleted");
                    exit;
                }

                header("Location: index.php?tab=List&error=" . urlencode("Ошибка API: " . $errMsg));
                exit;
            } else {
                header("Location: index.php?tab=List&error=" . urlencode("Пост не найден в базе/истории канала."));
                exit;
            }
        }
    }

    if ($_POST['action'] === 'edit_text') {
        $idToEdit = $_POST['id'];
        $newText = $_POST['text'];
        
        $stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ?");
        $stmt->execute([$idToEdit]);
        $r = $stmt->fetch();
        
        if ($r) {
            $platform = ($r['platform'] ?? 'max') === 'telegram' ? 'telegram' : 'max';
            $msgId = $r['post_message_id'] ?: ($platform === 'max' ? find_message_id_by_text($r['channel_id'], $r['text']) : null);
            $r['post_message_id'] = $msgId;
            $updateStmt = $pdo->prepare("UPDATE raffles SET text = ?, post_message_id = ? WHERE id = ?");
            $updateStmt->execute([$newText, $msgId, $idToEdit]);
            
            if ($msgId) {
                if ($platform === 'telegram') {
                    $res = admin_tg_edit_raffle_post($r, $newText);
                    if (empty($res['ok'])) {
                        $errMsg = "Изменено локально, но ошибка API Telegram: " . ($res['description'] ?? $res['message'] ?? 'Unknown');
                        header("Location: index.php?tab=List&error=" . urlencode($errMsg));
                        exit;
                    }
                } else {
                    $res = max_edit_message($msgId, $newText);
                    if (isset($res['success']) && $res['success'] === false) {
                        $errMsg = "Изменено локально, но ошибка API MAX: " . ($res['message'] ?? 'Unknown');
                        header("Location: index.php?tab=List&error=" . urlencode($errMsg));
                        exit;
                    }
                }
            } else {
                header("Location: index.php?tab=List&error=" . urlencode("Изменено только в БД (пост не найден в канале)"));
                exit;
            }
        }
        header("Location: index.php?tab=List&status=edited");
        exit;
    }
}

// Запрашиваем розыгрыши вместе с количеством участников
$raffles = $pdo->query("
    SELECT r.*, 
           (SELECT COUNT(*) FROM participants p WHERE p.raffle_id = r.id) as p_count 
    FROM raffles r 
    ORDER BY r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$channels = $pdo->query("SELECT id, platform, title, channel_env FROM channels ORDER BY platform ASC, channel_env ASC, title ASC")->fetchAll();
$broadcasts = $pdo->query("
    SELECT b.*,
           (SELECT COUNT(*) FROM broadcast_recipients br WHERE br.broadcast_id = b.id AND br.status = 'pending') AS pending_count,
           (SELECT COUNT(*) FROM broadcast_recipients br WHERE br.broadcast_id = b.id AND br.status = 'sent') AS sent_count_real,
           (SELECT COUNT(*) FROM broadcast_recipients br WHERE br.broadcast_id = b.id AND br.status = 'failed') AS failed_count_real
    FROM broadcasts b
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админка розыгрышей</title>
    <link rel="icon" type="image/x-icon" href="../icon.ico">
    <link rel="stylesheet" href="../styles.css?v=2">
    <style>
        .btn-delete { background-color: #d32f2f; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; width: 100%; text-align: center;}
        .btn-delete:hover { background-color: #b71c1c; }
        .btn-edit { background-color: #4a90e2; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; width: 100%; text-align: center; }
        .btn-edit:hover { background-color: #357abd; }
        .btn-warning { background-color: #e67e22; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; width: 100%; text-align: center; }
        .btn-warning:hover { background-color: #d35400; }
        .btn-sync { background-color: #27ae60; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-size: 14px; white-space: nowrap;}
        .btn-sync:hover { background-color: #219150; }

        .raffle-card { background: #252525; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .platform-card-max { border-left: 4px solid #4a90e2; }
        .platform-card-telegram { border-left: 4px solid #24a1de; background: linear-gradient(90deg, rgba(36,161,222,0.13), #252525 42%); }
        .platform-card-vk { border-left: 4px solid #4f46e5; background: linear-gradient(90deg, rgba(79,70,229,0.16), #252525 42%); }
        .platform-badge-max { background: #315f9f; color: #fff; }
        .platform-badge-telegram { background: #24a1de; color: #fff; }
        .platform-badge-vk { background: #4f46e5; color: #fff; }
        .channel-env-badge { padding: 2px 7px; border-radius: 999px; font-size: 11px; font-weight: 700; margin-left: 6px; }
        .channel-env-test { background: #334155; color: #e2e8f0; }
        .channel-env-main { background: #b45309; color: #fff7ed; }
        .platform-filter { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 14px; }
        .platform-filter button { width: auto; padding: 8px 12px; background: #333; color: #fff; border: 1px solid #444; border-radius: 8px; cursor: pointer; }
        .platform-filter button.active { background: #4a90e2; border-color: #4a90e2; }
        .platform-filter button[data-filter=telegram].active { background: #24a1de; border-color: #24a1de; }
        .platform-filter button[data-filter=vk].active { background: #4f46e5; border-color: #4f46e5; }
        .main-channel-warning { display: none; margin-top: 10px; padding: 12px; background: rgba(180,83,9,.14); border: 1px solid #b45309; color: #ffedd5; border-radius: 8px; }
        .main-channel-warning strong { color: #fff7ed; }
        .main-channel-warning label { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
        .main-channel-warning input { width: auto; }
        .raffle-info h3 { margin: 0 0 5px 0; font-size: 18px; }
        .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-pending { background: #FF9800; color: #000; }
        .status-active { background: #4CAF50; color: white; }
        .status-finished { background: #9E9E9E; color: white; }
        .status-processing { background: #03A9F4; color: white; }
        .status-done { background: #4CAF50; color: white; }
        .status-failed { background: #d32f2f; color: white; }
        .checkbox-wrapper { display: flex; align-items: center; gap: 10px; background: #2a2a2a; padding: 10px; border-radius: 8px; border: 1px solid #333; margin-top: 10px; }
        .checkbox-wrapper input[type="checkbox"] { width: 20px; height: 20px; cursor: pointer; }
        .help-text { font-size: 12px; color: #888; margin-top: 5px; margin-bottom: 5px; display: block;}
        .section-title { color: #4a90e2; border-bottom: 1px solid #333; padding-bottom: 5px; margin-top: 25px; margin-bottom: 15px; font-size: 18px; }
        .alert-box { background: #27ae60; color: white; padding: 10px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        input[type="file"], select { padding: 10px; background: #2a2a2a; color: #fff; border: 1px solid #333; width: 100%; box-sizing: border-box; border-radius: 4px;}
        
        /* ЧАТ */
        .chat-layout { display: flex; height: 600px; border: 1px solid #333; border-radius: 8px; overflow: hidden; background: #1e1e1e; }
        .chat-sidebar { width: 30%; border-right: 1px solid #333; display: flex; flex-direction: column; background: #252525; position: relative; }
        .chat-main { width: 70%; display: flex; flex-direction: column; background: #1e1e1e; }
        .user-item { padding: 15px; border-bottom: 1px solid #333; cursor: pointer; display: flex; align-items: center; gap: 10px; }
        .user-item:hover { background: #2c2c2c; }
        .user-item.active { background: #333; border-left: 3px solid #4a90e2; }
        .user-avatar { width: 40px; height: 40px; background: #444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .user-info { flex: 1; min-width: 0; }
        .user-name { font-weight: bold; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-meta { font-size: 11px; color: #888; display: flex; justify-content: space-between; }
        .unread-badge { background: #e24a4a; color: white; border-radius: 10px; padding: 2px 6px; font-size: 10px; }
        
        .chat-header { padding: 15px; border-bottom: 1px solid #333; background: #252525; font-weight: bold; }
        .messages-area { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background-image: radial-gradient(#252525 1px, transparent 1px); background-size: 20px 20px; }
        .message { max-width: 70%; padding: 10px 15px; border-radius: 10px; font-size: 14px; word-wrap: break-word; position: relative; }
        .msg-in { align-self: flex-start; background: #333; color: #eee; }
        .msg-out { align-self: flex-end; background: #4a90e2; color: #fff; }
        .msg-time { font-size: 10px; opacity: 0.7; text-align: right; margin-top: 5px; }
        .msg-img { max-width: 200px; border-radius: 5px; margin-bottom: 5px; }
        
        .chat-input { padding: 15px; border-top: 1px solid #333; background: #252525; display: flex; gap: 10px; align-items: center; }
        .chat-input input[type="text"] { flex: 1; }
        .btn-icon { cursor: pointer; padding: 8px; color: #aaa; }
        .btn-icon:hover { color: #fff; }

        .msg-actions { display: none; gap: 5px; margin-top: 5px; justify-content: flex-end; }
        .msg-out:hover .msg-actions { display: flex; }
        .msg-btn { background: rgba(0,0,0,0.3); border: none; color: #fff; cursor: pointer; padding: 3px 6px; border-radius: 4px; font-size: 12px; }
        .msg-btn:hover { background: rgba(0,0,0,0.6); }

        .modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:9999; align-items:center; justify-content:center; }
        .modal-content { background:#1e1e1e; padding:20px; border-radius:10px; width:90%; max-width:500px; border:1px solid #333; }
        
        .pagination-btn { flex: 1; padding: 8px; font-size: 12px; background: #333; color: #fff; border: 1px solid #444; border-radius: 4px; cursor: pointer; }
        .pagination-btn:hover { background: #444; }
    </style>
</head>
<body>

<div class="container" style="position: relative;">
    <a href="?action=logout" class="logout-btn">Выйти 🚪</a>

    <h1>🎯 Управление Розыгрышами</h1>
    <p class="center-text" style="color: #666; font-size: 12px;">Серверное время: <?= date('d.m.Y H:i:s') ?></p>

    <?php if ($statusMsg): ?>
        <div class="alert-box"><?= $statusMsg ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab <?= $activeTab === 'Create' ? 'active' : '' ?>" onclick="openTab(event, 'Create')">Создать</button>
        <button class="tab <?= $activeTab === 'List' ? 'active' : '' ?>" onclick="openTab(event, 'List')">Список</button>
        <button class="tab <?= $activeTab === 'Broadcasts' ? 'active' : '' ?>" onclick="openTab(event, 'Broadcasts')">📣 Рассылки</button>
        <button class="tab <?= $activeTab === 'Chat' ? 'active' : '' ?>" onclick="openTab(event, 'Chat')">💬 Чат</button>
        <button class="tab <?= $activeTab === 'Settings' ? 'active' : '' ?>" onclick="openTab(event, 'Settings')">⚙️ Настройки</button>
    </div>

    <div id="Create" class="tab-content <?= $activeTab === 'Create' ? 'active' : '' ?>">
        <form method="POST" class="search-form" id="createForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label>Площадка розыгрыша</label>
                <select name="platform" id="inp_platform" required>
                    <option value="max" selected>MAX</option>
                    <option value="telegram">Telegram</option>
                    <option value="vk">VK</option>
                </select>
                <small class="help-text">Выберите площадку, на которой будет проходить розыгрыш.</small>
            </div>
            
            <div class="form-inputs-row" style="align-items: flex-end; display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Название (для админки)</label>
                    <input type="text" name="title" id="inp_title" required placeholder="Например: Розыгрыш на 8 марта" style="width: 100%;">
                </div>
                
                <div class="form-group" id="createChannelGroup" style="flex: 1;">
                    <label id="createChannelLabel">Канал/чат для розыгрыша</label>
                    <div style="display: flex; gap: 10px;">
                        <select name="channel_id" id="inp_channel_id" style="flex: 1;">
                            <option value="">Выберите канал...</option>
                            <?php foreach ($channels as $ch): ?>
                                <?php
                                    $chPlatform = normalize_platform($ch['platform'] ?? 'max');
                                    $chEnv = ($ch['channel_env'] ?? 'test') === 'main' ? 'main' : 'test';
                                    if ($chPlatform === 'telegram') {
                                        $chPrefix = $chEnv === 'main' ? 'TG ОСНОВНОЙ' : 'TG ТЕСТ';
                                    } elseif ($chPlatform === 'vk') {
                                        $chPrefix = 'VK';
                                    } else {
                                        $chPrefix = 'MAX';
                                    }
                                ?>
                                <option value="<?= htmlspecialchars($ch['id']) ?>" data-platform="<?= htmlspecialchars($chPlatform) ?>" data-channel-env="<?= htmlspecialchars($chEnv) ?>">
                                    [<?= htmlspecialchars($chPrefix) ?>] <?= htmlspecialchars($ch['title']) ?> (ID: <?= htmlspecialchars($ch['id']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-sync" onclick="syncChannels()" id="btnSync">🔄 Обновить</button>
                    </div>
                </div>
            </div>


            <div id="mainChannelWarning" class="main-channel-warning">
                <strong>Внимание: выбран основной Telegram-канал.</strong><br>
                Пост розыгрыша уйдёт реальным подписчикам после запуска cron. Для тестов выбирай канал с меткой «Тестовый».
                <label>
                    <input type="checkbox" name="confirm_main_channel" id="confirmMainChannel" value="1">
                    Я понимаю, что публикация уйдёт в основной Telegram-канал
                </label>
            </div>

            <div class="form-group">
                <label>Текст поста</label>
                <textarea name="text" id="inp_text" rows="5" required placeholder="Текст розыгрыша..."></textarea>
            </div>
            <div class="form-group">
                <label>Медиа (Изображение/Видео)</label>
                <input type="file" name="media_file" id="inp_media_file" accept="image/*,video/*">
                <small class="help-text">Макс. размер: 50MB</small>
            </div>
            <div class="card promo-block">
                <h3>Промокоды</h3>
            
                <label>Текстовое поле (1 код = 1 строка)</label>
                <textarea name="create_codes_text" rows="8" placeholder="PROMO-111&#10;PROMO-222&#10;PROMO-333"></textarea>
            
                <br><br>
            
                <label>Файл TXT / CSV / XLSX</label>
                <input type="file" name="create_codes_file" accept=".txt,.csv,.xlsx">
            
                <br><br>
            
                <div class="card" style="background:#1a1a1a;">
                    <h4>Импорт из Google Таблицы</h4>
            
                    <label>Ссылка на Google Таблицу</label>
                    <input type="text" id="create_google_sheet_url" name="create_google_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/.../edit">
            
                    <br><br>
            
                    <button type="button" class="btn" onclick="loadCreateGoogleSheets()">Загрузить страницы</button>
            
                    <br><br>
            
                    <label>Страница с промокодами</label>
                    <select name="create_google_sheet_name" id="create_google_sheet_name">
                        <option value="">Сначала загрузите страницы</option>
                    </select>
                </div>
            </div>
            <div class="checkbox-wrapper">
                <input type="checkbox" name="check_subscription" id="subCheck" checked>
                <label for="subCheck" id="subCheckLabel">🔒 Требовать подписку на канал?</label>
            </div>
            <div class="checkbox-wrapper">
                <input type="checkbox" name="notify_non_winners" id="notifyNonWinners">
                <label for="notifyNonWinners">📩 Уведомлять не победителей после завершения?</label>
            </div>
            <div id="vkOptions" class="card" style="display:none;">
                <h3 style="margin-top:0;">VK-условия участия</h3>
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="vk_require_bot_message" id="vkRequireBotMessage" checked>
                    <label for="vkRequireBotMessage">✉️ Требовать, чтобы пользователь написал боту сообщества</label>
                </div>
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="vk_require_like" id="vkRequireLike" checked>
                    <label for="vkRequireLike">❤️ Проверять лайк на посте</label>
                </div>
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="vk_require_comment" id="vkRequireComment" checked>
                    <label for="vkRequireComment">💬 Требовать комментарий под постом</label>
                </div>
                <div class="form-group" style="margin-top:10px;">
                    <label>Тип комментария</label>
                    <select name="vk_comment_mode" id="vkCommentMode">
                        <option value="word">Слово / обычный комментарий</option>
                        <option value="number">Число в комментарии</option>
                    </select>
                </div>
                <div id="vkWordFields">
                    <div class="form-group" style="margin-top:10px;">
                        <label>Кодовое слово в комментарии</label>
                        <input type="text" name="vk_comment_word" id="vkCommentWord" placeholder="Например: ХОЧУ">
                        <small class="help-text">Если оставить пустым, подойдёт любой комментарий. Если заполнить — комментарий должен содержать это слово.</small>
                    </div>
                    <div class="checkbox-wrapper">
                        <input type="checkbox" name="vk_case_sensitive" id="vkCaseSensitive">
                        <label for="vkCaseSensitive">Учитывать регистр слова</label>
                    </div>
                </div>
                <div id="vkNumberFields" style="display:none;">
                    <div class="form-inputs-row" style="margin-top:10px;">
                        <div class="form-group">
                            <label>Минимальное число</label>
                            <input type="number" name="vk_number_min" id="vkNumberMin" placeholder="Например: 1">
                        </div>
                        <div class="form-group">
                            <label>Максимальное число</label>
                            <input type="number" name="vk_number_max" id="vkNumberMax" placeholder="Например: 100">
                        </div>
                    </div>
                    <small class="help-text">Участник должен написать число в комментарии. Победитель выбирается среди участников с подходящими числами.</small>
                </div>
            </div>
            <div class="form-inputs-row" style="margin-top: 10px; display: flex; gap: 15px;">
                <div class="form-group" style="flex: 1;">
                    <label>Дата начала</label>
                    <input type="datetime-local" name="start_date" id="inp_start_date" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Дата завершения</label>
                    <input type="datetime-local" name="end_date" id="inp_end_date" required>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label>Победителей</label>
                    <input type="number" name="winners_count" id="inp_winners_count" value="1" min="1" required>
                </div>
            </div>
            <button type="submit">🚀 Запланировать</button>
        </form>
    </div>

    <div id="List" class="tab-content <?= $activeTab === 'List' ? 'active' : '' ?>">
        <div class="platform-filter" data-target="raffle">
            <button type="button" data-filter="all" class="active">Все розыгрыши</button>
            <button type="button" data-filter="max">MAX</button>
            <button type="button" data-filter="telegram">Telegram</button>
            <button type="button" data-filter="vk">VK</button>
        </div>
        <div class="result">
            <?php if (empty($raffles)): ?>
                <p class="center-text">Пока нет розыгрышей</p>
            <?php else: ?>
                <?php foreach ($raffles as $r): ?>
                    <?php 
                        $statusClass = 'status-' . ($r['status'] ?? 'pending');
                        $statusLabel = $r['status'] ?? 'pending';
                        $subReq = !empty($r['check_subscription']) ? '🔒 Подписка' : '🔓 Без подписки';
                        $hasMedia = !empty($r['media_url']) ? '🖼 Есть медиа' : '📄 Без медиа';
                        $platform = $r['platform'] ?? 'max';
                        $platformLabel = $platform === 'telegram' ? 'Telegram' : ($platform === 'vk' ? 'VK' : 'MAX');
                        $canManagePost = in_array($platform, ['max','telegram'], true) && in_array($r['status'], ['active', 'finished']) && !empty($r['post_message_id']);
                        $pCount = $r['p_count'] ?? 0; // Количество участников
                    ?>
                    <div class="raffle-card platform-card-<?= htmlspecialchars($platform) ?>" data-platform="<?= htmlspecialchars($platform) ?>" data-card-type="raffle">
                        <div class="raffle-info">
                            <h3><?= htmlspecialchars($r['title']) ?> <span class="status-badge <?= $statusClass ?>"><?= $statusLabel ?></span> <span class="status-badge platform-badge-<?= htmlspecialchars($platform) ?>"><?= htmlspecialchars($platformLabel) ?></span></h3>
                            <div style="font-size: 13px; color: #aaa; margin-top: 5px;">
                                🗓 <?= date('d.m H:i', strtotime($r['start_date'])) ?> — <?= date('d.m H:i', strtotime($r['end_date'])) ?> <br>
                                ℹ️ <?= $subReq ?> | <?= $hasMedia ?> <br>
                                🏆 Победителей: <?= $r['winners_count'] ?> | 👥 Участников: <b style="color: #4CAF50;"><?= $pCount ?></b> | 📍 <?= htmlspecialchars($platformLabel) ?>
                            </div>
                            <div style="margin-top: 5px; font-size: 12px; color: #666;">ID: <?= $r['id'] ?> | Канал: <?= htmlspecialchars($r['channel_id']) ?></div>
                        </div>
                        
                        <div class="raffle-actions" style="display:flex; flex-direction:column; gap:5px; width: 140px;">
                            <a href="promocodes.php?raffle_id=<?= urlencode($r['id']) ?>" class="btn-edit promo-btn">
                                🎟 Промокоды
                            </a>
                            <?php if ($canManagePost): ?>
                                <button type="button" class="btn-edit" onclick="openEditModal('<?= $r['id'] ?>', `<?= htmlspecialchars($r['text'], ENT_QUOTES) ?>`)">✏️ Ред. пост</button>
                                <form method="POST" onsubmit="return confirm('Удалить пост из канала? (Должно пройти < 24 часов)');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn-warning">🗑 Удал. пост</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Удалить розыгрыш только из базы данных?');" style="margin:0; margin-top:5px;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn-delete">Удал. из БД</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="Broadcasts" class="tab-content <?= $activeTab === 'Broadcasts' ? 'active' : '' ?>">
        <form method="POST" class="search-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_broadcast">

            <div class="form-inputs-row" style="display:flex; gap:15px; align-items:flex-end;">
                <div class="form-group" style="flex:1;">
                    <label>Площадка рассылки</label>
                    <select name="broadcast_platform" id="broadcast_platform" required>
                        <option value="max" selected>MAX</option>
                        <option value="telegram">Telegram</option>
                    </select>
                    <small class="help-text">Аудитории MAX и Telegram не смешиваются.</small>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Название рассылки</label>
                    <input type="text" name="broadcast_title" required placeholder="Например: Анонс нового розыгрыша">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Аудитория</label>
                    <select name="broadcast_filter_type" required>
                        <option value="all_users">Все пользователи</option>
                        <option value="raffle_participants">Участники розыгрыша</option>
                        <option value="raffle_winners">Победители розыгрыша</option>
                        <option value="raffle_non_winners">Не победители розыгрыша</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Розыгрыш (для фильтров по розыгрышу)</label>
                <select name="broadcast_raffle_id" id="broadcast_raffle_id">
                    <option value="" data-platform="all">Не выбрано</option>
                    <?php foreach ($raffles as $r): ?>
                        <option value="<?= htmlspecialchars($r['id']) ?>" data-platform="<?= htmlspecialchars($r['platform'] ?? 'max') ?>">
                            [<?= ($r['platform'] ?? 'max') === 'telegram' ? 'TG' : (($r['platform'] ?? 'max') === 'vk' ? 'VK' : 'MAX') ?>] <?= htmlspecialchars($r['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="help-text">Для Telegram показываются только Telegram-розыгрыши, для MAX — только MAX.</small>
            </div>

            <div class="form-group">
                <label>Текст рассылки</label>
                <textarea name="broadcast_message_text" rows="5" required placeholder="Текст сообщения для рассылки"></textarea>
            </div>

            <div class="form-inputs-row" style="display:flex; gap:15px; align-items:flex-end;">
                <div class="form-group" style="flex:1;">
                    <label>Медиа</label>
                    <input type="file" name="broadcast_media_file" accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                    <small class="help-text">Текстовые рассылки продолжат работать без файла.</small>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Дата и время отправки (необязательно)</label>
                    <input type="datetime-local" name="broadcast_scheduled_at">
                    <small class="help-text">Если не указано — рассылка отправится при ближайшем запуске broadcast_cron.php.</small>
                </div>
            </div>

            <button type="submit">🚀 Создать рассылку</button>
        </form>

        <div class="platform-filter" data-target="broadcast" style="margin-top:20px;">
            <button type="button" data-filter="all" class="active">Все рассылки</button>
            <button type="button" data-filter="max">MAX</button>
            <button type="button" data-filter="telegram">Telegram</button>
            <button type="button" data-filter="vk">VK</button>
        </div>
        <div class="result" style="margin-top:20px;">
            <?php if (empty($broadcasts)): ?>
                <p class="center-text">Пока нет рассылок</p>
            <?php else: ?>
                <?php foreach ($broadcasts as $b): ?>
                    <?php
                        $pendingCount = (int)($b['pending_count'] ?? 0);
                        $sentCount = (int)($b['sent_count_real'] ?? $b['sent_count'] ?? 0);
                        $failedCount = (int)($b['failed_count_real'] ?? $b['failed_count'] ?? 0);
                        $canRunNow =
                            $b['status'] !== 'processing' &&
                            (
                                $pendingCount > 0 ||
                                $failedCount > 0
                            );
                    ?>
                    <?php $broadcastPlatformCard = normalize_platform($b['platform'] ?? 'max'); ?>
                    <div class="raffle-card platform-card-<?= htmlspecialchars($broadcastPlatformCard) ?>" data-platform="<?= htmlspecialchars($broadcastPlatformCard) ?>" data-card-type="broadcast">
                        <div class="raffle-info">
                            <h3><?= htmlspecialchars($b['title']) ?> <span class="status-badge status-<?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span> <span class="status-badge platform-badge-<?= htmlspecialchars($broadcastPlatformCard) ?>"><?= $broadcastPlatformCard === 'telegram' ? 'Telegram' : ($broadcastPlatformCard === 'vk' ? 'VK' : 'MAX') ?></span></h3>
                            <div style="font-size: 13px; color: #aaa; margin-top: 5px;">
                                👥 Всего: <b><?= (int)$b['total_recipients'] ?></b> | ✅ Отправлено: <b><?= $sentCount ?></b> | ⏳ В очереди: <b><?= $pendingCount ?></b> | ❌ Ошибок: <b><?= $failedCount ?></b><br>
                                📍 Площадка: <?= (($b['platform'] ?? 'max') === 'telegram') ? 'Telegram' : ((($b['platform'] ?? 'max') === 'vk') ? 'VK' : 'MAX') ?><br>
                                🧩 Фильтр: <?= htmlspecialchars($b['filter_type']) ?><?= !empty($b['raffle_id']) ? ' | Розыгрыш: ' . htmlspecialchars($b['raffle_id']) : '' ?><br>
                                ⏰ Запланировано: <?= !empty($b['scheduled_at']) ? htmlspecialchars($b['scheduled_at']) : 'сразу по cron' ?><br>
                                🚀 Старт: <?= !empty($b['started_at']) ? htmlspecialchars($b['started_at']) : 'ещё не начата' ?><br>
                                ✅ Завершена: <?= !empty($b['finished_at']) ? htmlspecialchars($b['finished_at']) : 'ещё не завершена' ?><br>
                                📎 Медиа: <?= !empty($b['media_type']) ? htmlspecialchars($b['media_type']) : 'нет' ?>
                            </div>
                        </div>

                        <div class="raffle-actions" style="display:flex; flex-direction:column; gap:5px; width: 160px;">
                            <?php if ($canRunNow): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="run_broadcast_now">
                                    <input type="hidden" name="broadcast_id" value="<?= htmlspecialchars($b['id']) ?>">
                                    <button type="submit" class="btn-edit">▶ Запустить сейчас</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Удалить рассылку и её очередь?');">
                                <input type="hidden" name="action" value="delete_broadcast">
                                <input type="hidden" name="broadcast_id" value="<?= htmlspecialchars($b['id']) ?>">
                                <button type="submit" class="btn-delete">🗑 Удалить</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="Chat" class="tab-content <?= $activeTab === 'Chat' ? 'active' : '' ?>">
        <div class="chat-layout">
            <div class="chat-sidebar">
                <div style="padding: 10px; border-bottom: 1px solid #333; background: #252525;">
                    <select id="chatPlatformFilter" style="width: 100%; box-sizing: border-box; padding: 8px; margin-bottom: 10px; background: #1e1e1e; color: #fff; border: 1px solid #333; border-radius: 4px;">
                        <option value="max" <?= $activeChatPlatform === 'max' ? 'selected' : '' ?>>MAX</option>
                        <option value="telegram" <?= $activeChatPlatform === 'telegram' ? 'selected' : '' ?>>Telegram</option>
                        <option value="vk" <?= $activeChatPlatform === 'vk' ? 'selected' : '' ?>>VK</option>
                    </select>
                    <input type="text" id="chatSearchName" placeholder="🔍 Поиск по имени..." style="width: 100%; box-sizing: border-box; padding: 8px; margin-bottom: 10px; background: #1e1e1e; color: #fff; border: 1px solid #333; border-radius: 4px;">
                    <select id="chatRaffleFilter" style="width: 100%; box-sizing: border-box; padding: 8px; background: #1e1e1e; color: #fff; border: 1px solid #333; border-radius: 4px;">
                        <option value="" data-platform="all">🏆 Все пользователи</option>
                        
                        <optgroup label="👥 Участники розыгрыша">
                            <?php foreach ($raffles as $r): ?>
                                <option value="part_<?= htmlspecialchars($r['id']) ?>" data-platform="<?= htmlspecialchars($r['platform'] ?? 'max') ?>">[<?= ($r['platform'] ?? 'max') === 'telegram' ? 'TG' : (($r['platform'] ?? 'max') === 'vk' ? 'VK' : 'MAX') ?>] <?= htmlspecialchars($r['title']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        
                        <optgroup label="🏆 Победители розыгрыша">
                            <?php foreach ($raffles as $r): ?>
                                <?php if ($r['status'] === 'finished'): ?>
                                    <option value="win_<?= htmlspecialchars($r['id']) ?>" data-platform="<?= htmlspecialchars($r['platform'] ?? 'max') ?>">[<?= ($r['platform'] ?? 'max') === 'telegram' ? 'TG' : (($r['platform'] ?? 'max') === 'vk' ? 'VK' : 'MAX') ?>] <?= htmlspecialchars($r['title']) ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>

                    </select>
                </div>
                <div id="userList" style="overflow-y: auto; flex: 1;">
                    <div style="padding:20px; text-align:center; color:#666;">Загрузка...</div>
                </div>
                <div id="userListControls" style="padding: 10px; display: none; gap: 10px; background: #252525; border-top: 1px solid #333;">
                    <button type="button" class="pagination-btn" onclick="loadMoreUsers()">Еще 10</button>
                    <button type="button" class="pagination-btn" onclick="loadAllUsers()">Показать всё</button>
                </div>
            </div>
            
            <div class="chat-main">
                <div class="chat-header" id="chatHeader">Выберите диалог</div>
                <div class="messages-area" id="msgArea"></div>
                <form id="chatForm" class="chat-input" style="display:none;" enctype="multipart/form-data">
                    <input type="hidden" id="chatUserId" name="user_id">
                    <input type="hidden" id="chatPlatform" name="platform" value="<?= htmlspecialchars($activeChatPlatform) ?>">
                    <label class="btn-icon" title="Прикрепить файл">
                        📎 <input type="file" name="file" id="chatFile" style="display: none;">
                    </label>
                    <span id="fileName" style="font-size:10px; color:#aaa; display:none;"></span>
                    <input type="text" name="text" id="chatText" placeholder="Сообщение..." autocomplete="off">
                    <button type="submit" style="width:auto; padding: 10px 15px;">➤</button>
                </form>
            </div>
        </div>
    </div>

    <div id="Settings" class="tab-content <?= $activeTab === 'Settings' ? 'active' : '' ?>">
        <form method="POST" class="search-form">
            <input type="hidden" name="action" value="save_settings">
            <div class="section-title">📢 Пост с итогами (В канал)</div>
            <div class="form-group">
                <label>Шаблон поста с победителями</label>
                <textarea name="winner_post_template" rows="5" placeholder="🏆 Итоги розыгрыша: {title}&#10;&#10;Поздравляем победителей:&#10;{winners}"><?= htmlspecialchars($settings['winner_post_template']) ?></textarea>
            </div>
            <div class="section-title">📩 Личное сообщение (Победителю)</div>
            <div class="form-group">
                <label>Текст сообщения в личку</label>
                <textarea name="winner_template" rows="4" placeholder="Поздравляем! Ты выиграл в {title}!&#10;&#10;Твой промокод:&#10;{promocode}"><?= htmlspecialchars($settings['winner_template']) ?></textarea>
            </div>
            <div class="section-title">🙌 Личное сообщение (Не победителю)</div>
            <div class="form-group">
                <label>Текст сообщения не победителю</label>
                <textarea name="non_winner_template" rows="4" placeholder="Спасибо за участие в розыгрыше {title}. В этот раз удача улыбнулась другим."><?= htmlspecialchars($settings['non_winner_template']) ?></textarea>
            </div>
            <div class="section-title">VK — текст условий в посте</div>
            <div class="form-group">
                <label>Текст после основного текста розыгрыша</label>
                <textarea name="vk_post_footer_template" rows="6"><?= htmlspecialchars($settings['vk_post_footer_template']) ?></textarea>
                <small class="help-text">Доступно: {comment_instruction}, {like_instruction}, {subscription_instruction}, {bot_instruction}, {title}</small>
            </div>
            <div class="section-title">VK — пост с итогами</div>
            <div class="form-group">
                <label>Если есть победители</label>
                <textarea name="vk_winner_post_template" rows="5"><?= htmlspecialchars($settings['vk_winner_post_template']) ?></textarea>
                <small class="help-text">Доступно: {title}, {winners}</small>
            </div>
            <div class="form-group">
                <label>Если участников нет</label>
                <textarea name="vk_no_participants_template" rows="3"><?= htmlspecialchars($settings['vk_no_participants_template']) ?></textarea>
                <small class="help-text">Доступно: {title}</small>
            </div>
            <div class="section-title">VK — личные сообщения</div>
            <div class="form-group">
                <label>Победителю</label>
                <textarea name="vk_winner_template" rows="4"><?= htmlspecialchars($settings['vk_winner_template']) ?></textarea>
                <small class="help-text">Доступно: {title}, {promocode}, {comment_number}, {comment_text}</small>
            </div>
            <div class="form-group">
                <label>Не победителю</label>
                <textarea name="vk_non_winner_template" rows="4"><?= htmlspecialchars($settings['vk_non_winner_template']) ?></textarea>
                <small class="help-text">Доступно: {title}</small>
            </div>
            <button type="submit">💾 Сохранить шаблоны</button>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#fff;">Редактировать пост</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_text">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Новый текст:</label>
                <textarea name="text" id="edit_text" rows="6" style="width:100%; box-sizing:border-box;"></textarea>
                <small style="color:#888; display:block; margin-top:5px;">Система сама найдет пост. Внимание: если прошло > 24ч, API может выдать ошибку.</small>
            </div>
            <div style="display:flex; gap:10px; margin-top:15px; justify-content:flex-end;">
                <button type="button" onclick="closeEditModal()" style="background:#444; width:auto;">Отмена</button>
                <button type="submit" style="width:auto;">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div id="editChatModal" class="modal">
    <div class="modal-content">
        <h3 style="margin-top:0; color:#fff;">Редактировать сообщение</h3>
        <form id="editChatForm">
            <div class="form-group">
                <textarea id="edit_chat_text" rows="5" style="width:100%; box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex; gap:10px; margin-top:15px; justify-content:flex-end;">
                <button type="button" onclick="closeEditChatModal()" style="background:#444; width:auto;">Отмена</button>
                <button type="submit" style="width:auto;">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
    async function loadCreateGoogleSheets() {
        const input = document.getElementById('create_google_sheet_url');
        const select = document.getElementById('create_google_sheet_name');
    
        const url = input.value.trim();
        if (!url) {
            alert('Вставь ссылку на Google Таблицу');
            return;
        }
    
        select.innerHTML = '<option value="">Загрузка...</option>';
    
        try {
            const response = await fetch('core/get_google_sheet_names.php?url=' + encodeURIComponent(url));
            const data = await response.json();
    
            if (data.error) {
                alert(data.error);
                select.innerHTML = '<option value="">Ошибка загрузки</option>';
                return;
            }
    
            select.innerHTML = '';
    
            if (!data.sheets || !data.sheets.length) {
                select.innerHTML = '<option value="">Страницы не найдены</option>';
                return;
            }
    
            const firstOption = document.createElement('option');
            firstOption.value = '';
            firstOption.textContent = 'Выбери страницу';
            select.appendChild(firstOption);
    
            data.sheets.forEach(sheetName => {
                const option = document.createElement('option');
                option.value = sheetName;
                option.textContent = sheetName;
                select.appendChild(option);
            });
        } catch (e) {
            alert('Не удалось загрузить список страниц');
            select.innerHTML = '<option value="">Ошибка загрузки</option>';
        }
    }
    
    function updateMainChannelWarning() {
        const platformSelect = document.getElementById('inp_platform');
        const channelSelect = document.getElementById('inp_channel_id');
        const warning = document.getElementById('mainChannelWarning');
        const confirmBox = document.getElementById('confirmMainChannel');
        if (!platformSelect || !channelSelect || !warning) return;

        const selectedPlatform = platformSelect.value || 'max';
        const selectedOption = channelSelect.options[channelSelect.selectedIndex];
        const isMainTelegram = selectedPlatform === 'telegram' && selectedOption && selectedOption.dataset.channelEnv === 'main';
        warning.style.display = isMainTelegram ? 'block' : 'none';
        if (!isMainTelegram && confirmBox) confirmBox.checked = false;

        const vkOptions = document.getElementById('vkOptions');
        if (vkOptions) vkOptions.style.display = selectedPlatform === 'vk' ? 'block' : 'none';

        const channelGroup = document.getElementById('createChannelGroup');
        if (channelGroup) channelGroup.style.display = selectedPlatform === 'vk' ? 'none' : 'flex';

        const subLabel = document.getElementById('subCheckLabel');
        if (subLabel) {
            subLabel.textContent = selectedPlatform === 'vk' ? '🔒 Требовать подписку на VK-сообщество?' : '🔒 Требовать подписку на канал?';
        }

        channelSelect.required = selectedPlatform !== 'vk';
    }

    function filterChannelsByPlatform() {
        const platformSelect = document.getElementById('inp_platform');
        const channelSelect = document.getElementById('inp_channel_id');

        if (!platformSelect || !channelSelect) return;

        const selectedPlatform = platformSelect.value || 'max';
        let selectedStillVisible = false;

        if (selectedPlatform === 'vk') {
            channelSelect.value = '';
            Array.from(channelSelect.options).forEach(option => option.hidden = option.value !== '');
            updateMainChannelWarning();
            return;
        }

        Array.from(channelSelect.options).forEach(option => {
            if (!option.value) {
                option.hidden = false;
                return;
            }

            const optionPlatform = option.dataset.platform || 'max';
            const visible = optionPlatform === selectedPlatform;
            option.hidden = !visible;

            if (visible && option.selected) {
                selectedStillVisible = true;
            }
        });

        if (!selectedStillVisible) {
            channelSelect.value = '';
        }
        updateMainChannelWarning();
    }

    function updateVkCommentMode() {
        const mode = document.getElementById('vkCommentMode');
        const wordFields = document.getElementById('vkWordFields');
        const numberFields = document.getElementById('vkNumberFields');
        if (!mode || !wordFields || !numberFields) return;
        const isNumber = mode.value === 'number';
        wordFields.style.display = isNumber ? 'none' : 'block';
        numberFields.style.display = isNumber ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        filterChannelsByPlatform();
        updateVkCommentMode();
        const vkMode = document.getElementById('vkCommentMode');
        if (vkMode) vkMode.addEventListener('change', updateVkCommentMode);
    });

    function filterBroadcastRafflesByPlatform() {
        const platformSelect = document.getElementById('broadcast_platform');
        const raffleSelect = document.getElementById('broadcast_raffle_id');
        if (!platformSelect || !raffleSelect) return;

        const selectedPlatform = ['telegram','vk'].includes(platformSelect.value) ? platformSelect.value : 'max';
        let selectedStillVisible = false;
        Array.from(raffleSelect.options).forEach(option => {
            const optionPlatform = option.dataset.platform || 'max';
            const visible = optionPlatform === 'all' || optionPlatform === selectedPlatform;
            option.hidden = !visible;
            if (visible && option.selected) selectedStillVisible = true;
        });
        if (!selectedStillVisible) raffleSelect.value = '';
    }

    document.addEventListener('DOMContentLoaded', filterBroadcastRafflesByPlatform);

    function initPlatformCardFilters() {
        document.querySelectorAll('.platform-filter').forEach(group => {
            const target = group.dataset.target || 'raffle';
            const storageKey = 'platform_filter_' + target;
            const saved = localStorage.getItem(storageKey) || 'all';

            function apply(filter) {
                group.querySelectorAll('button[data-filter]').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.filter === filter);
                });
                document.querySelectorAll('[data-card-type="' + target + '"]').forEach(card => {
                    const visible = filter === 'all' || card.dataset.platform === filter;
                    card.style.display = visible ? '' : 'none';
                });
                localStorage.setItem(storageKey, filter);
            }

            group.querySelectorAll('button[data-filter]').forEach(btn => {
                btn.addEventListener('click', () => apply(btn.dataset.filter || 'all'));
            });
            apply(saved);
        });
    }

    document.addEventListener('DOMContentLoaded', initPlatformCardFilters);

    function syncChannels() {
        const btn = document.getElementById('btnSync');
        const oldText = btn.innerText;
        btn.innerText = '⏳ Синхронизация...';
        btn.disabled = true;

        fetch('../tools/sync_channels.php?ajax=1')
            .then(r => r.json())
            .then(res => {
                btn.innerText = oldText;
                btn.disabled = false;
                alert(res.message);
                if (res.success) location.reload();
            })
            .catch(err => {
                btn.innerText = oldText;
                btn.disabled = false;
                alert('Ошибка соединения при синхронизации.');
            });
    }

    function openTab(evt, tabName) {
        var i, tabcontent, tablinks;
        tabcontent = document.getElementsByClassName("tab-content");
        for (i = 0; i < tabcontent.length; i++) {
            tabcontent[i].style.display = "none";
            tabcontent[i].classList.remove("active");
        }
        tablinks = document.getElementsByClassName("tab");
        for (i = 0; i < tablinks.length; i++) {
            tablinks[i].className = tablinks[i].className.replace(" active", "");
        }
        document.getElementById(tabName).style.display = "block";
        document.getElementById(tabName).classList.add("active");
        if(evt && evt.currentTarget) evt.currentTarget.className += " active";
    }

    function openEditModal(id, text) {
        document.getElementById('editModal').style.display = 'flex';
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_text').value = text;
    }
    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    let currentEditChat = {};

    window.editChatMsg = function(userId, time, msgId, encText) {
        const oldText = decodeURIComponent(encText);
        currentEditChat = { userId, time, msgId, oldText };
        document.getElementById('edit_chat_text').value = oldText;
        document.getElementById('editChatModal').style.display = 'flex';
    };

    window.closeEditChatModal = function() {
        document.getElementById('editChatModal').style.display = 'none';
    };

    document.getElementById('editChatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const newText = document.getElementById('edit_chat_text').value;
        if (newText === currentEditChat.oldText || newText.trim() === '') {
            closeEditChatModal();
            return;
        }

        const fd = new FormData();
        fd.append('action', 'edit');
        fd.append('user_id', currentEditChat.userId);
        fd.append('platform', currentChatPlatform);
        fd.append('time', currentEditChat.time);
        fd.append('msg_id', currentEditChat.msgId);
        fd.append('search_text', currentEditChat.oldText);
        fd.append('text', newText);

        fetch('core/chat_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    closeEditChatModal();
                    if (window.forceLoadHistory) window.forceLoadHistory();
                } else {
                    alert('Ошибка: ' + res.error);
                }
            });
    });

    window.deleteChatMsg = function(userId, time, msgId, encText) {
        if(!confirm('Удалить сообщение? (Должно пройти < 24 часов)')) return;
        
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('user_id', userId);
        fd.append('platform', currentChatPlatform);
        fd.append('time', time);
        fd.append('msg_id', msgId);
        fd.append('search_text', decodeURIComponent(encText));

        fetch('core/chat_action.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    if (window.forceLoadHistory) window.forceLoadHistory();
                } else {
                    alert('Ошибка: ' + res.error);
                }
            });
    };

    document.addEventListener("DOMContentLoaded", function() {
        const createForm = document.getElementById('createForm');
        const createFields = ['inp_title', 'inp_channel_id', 'inp_text', 'inp_start_date', 'inp_end_date', 'inp_winners_count'];
        const platformField = document.getElementById('inp_platform');
        const broadcastPlatformField = document.getElementById('broadcast_platform');
        if (broadcastPlatformField) {
            broadcastPlatformField.addEventListener('change', filterBroadcastRafflesByPlatform);
            filterBroadcastRafflesByPlatform();
        }

        function getCreatePlatform() {
            return platformField && ['telegram','vk'].includes(platformField.value) ? platformField.value : 'max';
        }

        function draftKey(id, platform = getCreatePlatform()) {
            return 'draft_' + platform + '_' + id;
        }

        function saveCreateDraft(platform = getCreatePlatform()) {
            createFields.forEach(id => {
                const el = document.getElementById(id);
                if (el) localStorage.setItem(draftKey(id, platform), el.value || '');
            });
        }

        function loadCreateDraft(platform = getCreatePlatform()) {
            createFields.forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const saved = localStorage.getItem(draftKey(id, platform));
                el.value = saved !== null ? saved : (id === 'inp_winners_count' ? '1' : '');
            });
            filterChannelsByPlatform();
        }

        let currentCreatePlatform = getCreatePlatform();

        if (platformField) {
            const savedPlatform = localStorage.getItem('draft_active_platform');
            if (savedPlatform === 'telegram' || savedPlatform === 'max' || savedPlatform === 'vk') {
                platformField.value = savedPlatform;
            }
            currentCreatePlatform = getCreatePlatform();
            filterChannelsByPlatform();
            loadCreateDraft(currentCreatePlatform);
            updateMainChannelWarning();

            platformField.addEventListener('change', function(e) {
                saveCreateDraft(currentCreatePlatform);
                currentCreatePlatform = getCreatePlatform();
                localStorage.setItem('draft_active_platform', currentCreatePlatform);
                loadCreateDraft(currentCreatePlatform);
                updateMainChannelWarning();
            });
        }

        const createChannelField = document.getElementById('inp_channel_id');
        if (createChannelField) {
            createChannelField.addEventListener('change', function() {
                saveCreateDraft();
                updateMainChannelWarning();
            });
        }

        if(createForm) {
            createForm.addEventListener('input', function(e) {
                if (createFields.includes(e.target.id)) saveCreateDraft();
            });
            createForm.addEventListener('submit', function(e) {
                const selectedOption = createChannelField ? createChannelField.options[createChannelField.selectedIndex] : null;
                const confirmMain = document.getElementById('confirmMainChannel');
                if (getCreatePlatform() === 'telegram' && selectedOption && selectedOption.dataset.channelEnv === 'main' && confirmMain && !confirmMain.checked) {
                    e.preventDefault();
                    alert('Выбран основной Telegram-канал. Поставь подтверждающую галочку, если публикация действительно должна уйти подписчикам.');
                    updateMainChannelWarning();
                    return;
                }
                const currentPlatform = getCreatePlatform();
                createFields.forEach(id => localStorage.removeItem(draftKey(id, currentPlatform)));
            });
        }
        
        let activeChatId = "<?= $activeChatId ?>";
        let currentChatPlatform = "<?= $activeChatPlatform ?>";
        const chatPlatformFilter = document.getElementById('chatPlatformFilter');
        const userListEl = document.getElementById('userList');
        const userListControls = document.getElementById('userListControls');
        const msgArea = document.getElementById('msgArea');
        const chatHeader = document.getElementById('chatHeader');
        const chatForm = document.getElementById('chatForm');

        let currentSearch = '';
        let currentRaffle = '';
        let currentRaffleType = '';
        let currentLimit = 10;

        function syncChatRaffleFilterOptions() {
            if (!chatPlatformFilter) return;
            const selectedPlatform = chatPlatformFilter.value || 'max';
            Array.from(document.getElementById('chatRaffleFilter').options).forEach(option => {
                const optionPlatform = option.dataset.platform || 'max';
                option.hidden = !(optionPlatform === 'all' || optionPlatform === selectedPlatform);
                if (option.hidden && option.selected) option.selected = false;
            });
            if (!document.getElementById('chatRaffleFilter').value) {
                currentRaffleType = '';
                currentRaffle = '';
            }
        }

        chatPlatformFilter.addEventListener('change', function(e) {
            currentChatPlatform = ['telegram','vk'].includes(e.target.value) ? e.target.value : 'max';
            document.getElementById('chatPlatform').value = currentChatPlatform;
            activeChatId = '';
            document.getElementById('chatUserId').value = '';
            chatHeader.innerText = currentChatPlatform === 'telegram' ? 'Выберите Telegram-диалог' : (currentChatPlatform === 'vk' ? 'Выберите VK-диалог' : 'Выберите MAX-диалог');
            chatForm.style.display = 'none';
            msgArea.innerHTML = '';
            currentSearch = '';
            currentRaffle = '';
            currentRaffleType = '';
            currentLimit = 10;
            document.getElementById('chatSearchName').value = '';
            document.getElementById('chatRaffleFilter').value = '';
            localStorage.setItem('chat_platform', currentChatPlatform);
            syncChatRaffleFilterOptions();
            loadUsers();
        });

        document.getElementById('chatSearchName').addEventListener('input', function(e) {
            currentSearch = e.target.value;
            currentLimit = 10;
            loadUsers();
        });

        // НОВАЯ ЛОГИКА ФИЛЬТРА ЧАТА
        document.getElementById('chatRaffleFilter').addEventListener('change', function(e) {
            const val = e.target.value;
            if (val.startsWith('part_')) {
                currentRaffleType = 'part';
                currentRaffle = val.replace('part_', '');
            } else if (val.startsWith('win_')) {
                currentRaffleType = 'win';
                currentRaffle = val.replace('win_', '');
            } else {
                currentRaffleType = '';
                currentRaffle = '';
            }
            currentLimit = 10;
            loadUsers();
        });

        window.loadMoreUsers = function() {
            currentLimit += 10;
            loadUsers();
        };

        window.loadAllUsers = function() {
            currentLimit = 'all';
            loadUsers();
        };
        
        function loadUsers() {
            const params = new URLSearchParams();
            params.append('platform', currentChatPlatform);
            if (currentSearch) params.append('search', currentSearch);
            
            // В зависимости от префикса шлем нужный параметр
            if (currentRaffleType === 'part') {
                params.append('raffle_id', currentRaffle);
            } else if (currentRaffleType === 'win') {
                params.append('winner_raffle_id', currentRaffle);
            }
            
            params.append('limit', currentLimit);

            fetch('core/get_users.php?' + params.toString())
                .then(r => r.json())
                .then(users => {
                    if (!Array.isArray(users)) {
                        // Ошибка авторизации или сервера
                        userListEl.innerHTML = '<div style="padding:20px; text-align:center; font-size:12px; color:#c00;">' 
                            + (users.error || 'Ошибка загрузки') + '</div>';
                        return;
                    }
                    if(users.length === 0) {
                        userListEl.innerHTML = '<div style="padding:20px; text-align:center; font-size:12px; color:#666;">Нет подходящих диалогов</div>';
                        userListControls.style.display = 'none';
                        return;
                    }
                    
                    let html = '';
                    users.forEach(u => {
                        const isActive = (u.user_id == activeChatId) ? 'active' : '';
                        const unread = (u.unread > 0) ? `<div class="unread-badge">${u.unread}</div>` : '';
                        const date = new Date(u.mess_date * 1000).toLocaleString('ru-RU', {day:'numeric', month:'numeric', hour:'2-digit', minute:'2-digit'});
                        
                        html += `
                        <div class="user-item ${isActive}" onclick="openChat('${u.user_id}', '${u.name}')">
                            <div class="user-avatar">${u.name ? u.name[0] : 'U'}</div>
                            <div class="user-info">
                                <div class="user-name">${u.name || 'ID: '+u.user_id}</div>
                                <div class="user-meta"><span>${date}</span> · <span>${currentChatPlatform === 'telegram' ? 'Telegram' : (currentChatPlatform === 'vk' ? 'VK' : 'MAX')}</span></div>
                            </div>
                            ${unread}
                        </div>`;
                    });
                    
                    if(userListEl.innerHTML !== html) userListEl.innerHTML = html;

                    if (currentLimit !== 'all' && users.length >= currentLimit) {
                        userListControls.style.display = 'flex';
                    } else {
                        userListControls.style.display = 'none';
                    }
                });
        }
        
        window.openChat = function(uid, name) {
            activeChatId = uid;
            document.getElementById('chatUserId').value = uid;
            document.getElementById('chatPlatform').value = currentChatPlatform;
            chatHeader.innerText = (currentChatPlatform === 'telegram' ? 'Telegram: ' : (currentChatPlatform === 'vk' ? 'VK: ' : 'MAX: ')) + (name || 'ID: ' + uid);
            chatForm.style.display = 'flex';
            msgArea.innerHTML = '<div style="text-align:center; padding:20px; color:#666;">Загрузка...</div>';
            loadUsers();
            loadHistory();
        };
        
        function loadHistory() {
            if(!activeChatId) return;
            fetch('core/get_history.php?user_id=' + activeChatId + '&platform=' + currentChatPlatform + '&t=' + Date.now())
                .then(r => r.json())
                .then(msgs => {
                    let html = '';
                    msgs.forEach(m => {
                        const cls = (m.dir === 'out') ? 'msg-out' : 'msg-in';
                        const time = new Date(m.time * 1000).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        
                        let content = m.content;
                        let actions = '';

                        if (m.dir === 'out' && currentChatPlatform === 'max') {
                            const mId = m.msg_id || '';
                            const encText = encodeURIComponent(m.content || '');
                            let editBtn = (m.type === 'text') ? `<button type="button" class="msg-btn" onclick="editChatMsg('${activeChatId}', ${m.time}, '${mId}', '${encText}')" title="Редактировать">✏️</button>` : '';
                            
                            actions = `
                            <div class="msg-actions">
                                ${editBtn}
                                <button type="button" class="msg-btn" onclick="deleteChatMsg('${activeChatId}', ${m.time}, '${mId}', '${encText}')" title="Удалить">🗑</button>
                            </div>`;
                        }
                        
                        if(m.type === 'photo') {
                            content = `<a href="${m.content}" target="_blank"><img src="${m.content}" class="msg-img"></a>`;
                        } else if(m.type === 'file') {
                            content = `<a href="${m.content}" target="_blank" style="color:inherit">📄 Файл</a>`;
                        }
                        
                        html += `<div class="message ${cls}">${content}${actions}<div class="msg-time">${time}</div></div>`;
                    });
                    
                    const isAtBottom = (msgArea.scrollHeight - msgArea.scrollTop <= msgArea.clientHeight + 50);
                    msgArea.innerHTML = html;
                    if(isAtBottom) msgArea.scrollTop = msgArea.scrollHeight;
                });
        }

        window.forceLoadHistory = loadHistory; 

        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('chatPlatform').value = currentChatPlatform;
            const fd = new FormData(this);
            const btn = this.querySelector('button');
            const oldText = btn.innerText;
            
            btn.disabled = true; 
            btn.innerText = '...';
            
            fetch('core/send.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    btn.innerText = oldText;
                    if(res.success) {
                        document.getElementById('chatText').value = '';
                        document.getElementById('chatFile').value = '';
                        document.getElementById('fileName').style.display = 'none';
                        loadHistory();
                    } else {
                        alert('Ошибка: ' + (res.error || 'Неизвестная ошибка'));
                    }
                });
        });

        document.getElementById('chatFile').addEventListener('change', function() {
            const n = document.getElementById('fileName');
            if(this.files[0]) {
                n.style.display = 'inline';
                n.innerText = this.files[0].name;
            } else {
                n.style.display = 'none';
            }
        });

        syncChatRaffleFilterOptions();
        setInterval(loadUsers, 5000);
        setInterval(() => { if(activeChatId) loadHistory(); }, 3000);
        loadUsers();
        
        if(activeChatId) openChat(activeChatId, 'Загрузка...');
    });
</script>
</body>
</html>