<?php
date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

/**
 * Получает безопасное PDO-соединение с базой данных
 */
function get_db_connection() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn     = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Отключаем эмуляцию подготовленных запросов для максимальной защиты от SQL-инъекций
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            AppLogger::error('Не удалось подключиться к базе данных', [
                'host' => DB_HOST,
                'db'   => DB_NAME,
            ], $e);
            // Возвращаем корректный HTTP 500 с JSON, чтобы клиент мог обработать ошибку
            http_response_code(500);
            header('Content-Type: application/json');
            exit(json_encode(['success' => false, 'message' => 'Ошибка базы данных. Попробуйте позже.']));
        }
    }
    return $pdo;
}

function generate_uuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Нормализует название площадки.
 */
function normalize_platform($platform, string $default = 'max'): string {
    $platform = strtolower(trim((string)$platform));
    return in_array($platform, ['max', 'telegram', 'vk'], true) ? $platform : $default;
}

/**
 * Добавляет/обновляет поля platform для разделения MAX, Telegram и VK без ручных миграций.
 * Вызов безопасный: если поле уже есть, ошибка ALTER просто игнорируется.
 */
function ensure_platform_columns(PDO $pdo): void {
    // Для VK user_id хранится как строка вида vk_123456.
    // Поэтому переводим идентификаторы пользователей/сообщений/участников в VARCHAR.
    // MAX и Telegram продолжают работать: их числовые ID просто хранятся строкой.
    try { $pdo->exec("ALTER TABLE users MODIFY COLUMN id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE messages MODIFY COLUMN user_id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE participants MODIFY COLUMN user_id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}

    // VK использует технические ID вида vk_123456789. Поэтому поля каналов
    // и channel_id у розыгрышей тоже должны быть строковыми, иначе MySQL падает
    // при создании VK-розыгрыша с ошибкой 1366 Incorrect integer value.
    try { $pdo->exec("ALTER TABLE channels MODIFY COLUMN id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles MODIFY COLUMN channel_id VARCHAR(64) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffle_promocodes MODIFY COLUMN assigned_to_user_id VARCHAR(64) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcast_recipients MODIFY COLUMN user_id VARCHAR(64) NOT NULL"); } catch (Exception $e) {}

    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE channels ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE channels ADD COLUMN channel_env ENUM('test','main') NOT NULL DEFAULT 'test' AFTER platform"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE messages ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER user_id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}

    // Поля для VK-розыгрышей. На этом этапе они сохраняются, а сбор участников подключим следующим шагом.
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_like TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_non_winners"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_comment TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_require_like"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_comment_mode ENUM('word','number') NOT NULL DEFAULT 'word' AFTER vk_require_comment"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_comment_word VARCHAR(255) NULL AFTER vk_comment_mode"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_number_min INT NULL AFTER vk_comment_word"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_number_max INT NULL AFTER vk_number_min"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_case_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER vk_number_max"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE raffles ADD COLUMN vk_require_bot_message TINYINT(1) NOT NULL DEFAULT 1 AFTER vk_case_sensitive"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE participants ADD COLUMN meta TEXT NULL AFTER name"); } catch (Exception $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS vk_comment_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            raffle_id VARCHAR(64) NOT NULL,
            owner_id BIGINT NOT NULL,
            post_id BIGINT NOT NULL,
            comment_id BIGINT NOT NULL,
            user_id VARCHAR(64) NOT NULL,
            vk_user_id BIGINT NOT NULL,
            text TEXT NULL,
            created_at INT NULL,
            accepted TINYINT(1) NOT NULL DEFAULT 0,
            skip_reason VARCHAR(64) NULL,
            meta TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_vk_comment (owner_id, post_id, comment_id),
            KEY idx_raffle_id (raffle_id),
            KEY idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}

    // Если поля уже существовали как ENUM('max','telegram'), расширяем их до VK.
    try { $pdo->exec("ALTER TABLE raffles MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE channels MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE messages MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts MODIFY COLUMN platform ENUM('max','telegram','vk') NOT NULL DEFAULT 'max'"); } catch (Exception $e) {}

    // Перенос уже созданных Telegram-пользователей из прошлой версии, где поля platform ещё не было.
    try { $pdo->exec("UPDATE users SET platform = 'telegram' WHERE status = 'Telegram'"); } catch (Exception $e) {}
    try { $pdo->exec("UPDATE messages m INNER JOIN users u ON u.id = m.user_id SET m.platform = u.platform WHERE u.platform IN ('telegram','vk')"); } catch (Exception $e) {}
}


/**
 * Создаёт технический VK-канал в таблице channels по VK_GROUP_ID из config.php.
 * Это нужно, чтобы VK можно было выбирать в форме создания розыгрыша так же, как MAX/TG.
 */
function ensure_vk_group_channel(PDO $pdo): void {
    if (!defined('VK_GROUP_ID')) {
        return;
    }

    $groupId = trim((string)VK_GROUP_ID);
    $groupId = ltrim($groupId, '-');
    if ($groupId === '') {
        return;
    }

    $channelId = 'vk_' . $groupId;
    $title = 'VK сообщество ' . $groupId;

    try {
        $stmt = $pdo->prepare("INSERT INTO channels (id, platform, channel_env, title, raw_data) VALUES (?, 'vk', 'test', ?, ?) ON DUPLICATE KEY UPDATE platform = 'vk', title = VALUES(title), raw_data = VALUES(raw_data)");
        $stmt->execute([$channelId, $title, json_encode(['group_id' => $groupId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Exception $e) {
        try {
            $stmt = $pdo->prepare("INSERT INTO channels (id, platform, title) VALUES (?, 'vk', ?) ON DUPLICATE KEY UPDATE platform = 'vk', title = VALUES(title)");
            $stmt->execute([$channelId, $title]);
        } catch (Exception $ignored) {}
    }
}

?>