<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/max_api.php';

function detect_broadcast_media_type(string $filePath): string {
    $mime = mime_content_type($filePath) ?: 'application/octet-stream';

    if (strpos($mime, 'image/') === 0) {
        return 'image';
    }
    if (strpos($mime, 'video/') === 0) {
        return 'video';
    }
    if (strpos($mime, 'audio/') === 0) {
        return 'audio';
    }
    return 'file';
}

function ensure_broadcast_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcasts (
        id VARCHAR(36) PRIMARY KEY,
        platform ENUM('max','telegram') NOT NULL DEFAULT 'max',
        title VARCHAR(255) NOT NULL,
        filter_type VARCHAR(50) NOT NULL,
        raffle_id VARCHAR(36) NULL,
        message_text TEXT NOT NULL,
        media_url VARCHAR(255) NULL,
        media_type VARCHAR(20) NULL,
        media_payload LONGTEXT NULL,
        scheduled_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        total_recipients INT NOT NULL DEFAULT 0,
        sent_count INT NOT NULL DEFAULT 0,
        failed_count INT NOT NULL DEFAULT 0,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_status_scheduled (status, scheduled_at),
        KEY idx_platform_status_scheduled (platform, status, scheduled_at),
        KEY idx_raffle_id (raffle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN platform ENUM('max','telegram') NOT NULL DEFAULT 'max' AFTER id"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN media_payload LONGTEXT NULL AFTER media_type"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN scheduled_at DATETIME NULL AFTER media_payload"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN started_at DATETIME NULL AFTER failed_count"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD COLUMN finished_at DATETIME NULL AFTER started_at"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE broadcasts ADD KEY idx_platform_status_scheduled (platform, status, scheduled_at)"); } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_recipients (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        broadcast_id VARCHAR(36) NOT NULL,
        user_id BIGINT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        error_text TEXT NULL,
        sent_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_broadcast_user (broadcast_id, user_id),
        KEY idx_broadcast_status (broadcast_id, status),
        KEY idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function normalize_platform_value(string $platform): string {
    return $platform === 'telegram' ? 'telegram' : 'max';
}


function safe_upload_extension(string $originalName, string $tmpPath = ''): string {
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';

    if ($ext === '' && $tmpPath !== '' && is_file($tmpPath)) {
        $mime = mime_content_type($tmpPath) ?: '';
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.ms-excel' => 'xls',
        ];
        $ext = $map[$mime] ?? '';
    }

    if ($ext === '') {
        $ext = 'bin';
    }

    return substr($ext, 0, 12);
}

function make_safe_uploaded_filename(string $prefix, string $originalName, string $tmpPath = ''): string {
    $prefix = strtolower(preg_replace('/[^a-z0-9]+/', '_', $prefix));
    $prefix = trim($prefix, '_') ?: 'upload';
    $ext = safe_upload_extension($originalName, $tmpPath);

    try {
        $random = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $random = md5(uniqid('', true));
    }

    return $prefix . '_' . date('Ymd_His') . '_' . $random . '.' . $ext;
}

function build_project_file_url(string $projectUrl, string $relativePath): string {
    $relativePath = '/' . ltrim($relativePath, '/');
    $parts = array_map('rawurlencode', explode('/', ltrim($relativePath, '/')));
    return rtrim($projectUrl, '/') . '/' . implode('/', $parts);
}

function project_media_url_to_local_path(?string $mediaUrl, string $projectUrl): ?string {
    $mediaUrl = trim((string)$mediaUrl);
    if ($mediaUrl === '') {
        return null;
    }

    $projectUrl = rtrim($projectUrl, '/');
    if (strpos($mediaUrl, $projectUrl . '/') !== 0) {
        return null;
    }

    $relative = substr($mediaUrl, strlen($projectUrl));
    $relative = rawurldecode(parse_url($relative, PHP_URL_PATH) ?: $relative);
    $relative = '/' . ltrim($relative, '/');

    if (strpos($relative, '..') !== false) {
        return null;
    }

    $path = realpath(__DIR__ . '/..' . $relative);
    $root = realpath(__DIR__ . '/..');
    if ($path === false || $root === false || strpos($path, $root) !== 0 || !is_file($path)) {
        return null;
    }

    return $path;
}

function save_broadcast_media(array $file, string $projectUrl, string $platform = 'max'): array {
    $platform = normalize_platform_value($platform);

    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [
            'media_url' => null,
            'media_type' => null,
            'media_payload' => null,
        ];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Ошибка загрузки медиафайла.');
    }

    $uploadDir = __DIR__ . '/../uploads/broadcasts/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $newName = make_safe_uploaded_filename('broadcast', (string)($file['name'] ?? 'file.bin'), (string)($file['tmp_name'] ?? ''));
    $destPath = $uploadDir . $newName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Не удалось сохранить медиафайл на сервере.');
    }

    $mediaType = detect_broadcast_media_type($destPath);
    $mediaUrl = build_project_file_url($projectUrl, '/uploads/broadcasts/' . $newName);
    $mediaPayload = null;

    // Для MAX файл нужно заранее загрузить в MAX. Для Telegram сохраняем локальный путь,
    // чтобы отправлять файл напрямую в Bot API и не зависеть от имени/URL файла.
    if ($platform === 'max') {
        $attachment = max_prepare_attachment_from_file($destPath, $mediaType);
        $mediaPayload = json_encode($attachment['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($platform === 'telegram') {
        $mediaPayload = json_encode(['local_path' => $destPath], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return [
        'media_url' => $mediaUrl,
        'media_type' => $mediaType,
        'media_payload' => $mediaPayload,
    ];
}

function get_broadcast_winner_ids(PDO $pdo, string $raffleId, string $platform = 'max'): array {
    $platform = normalize_platform_value($platform);
    $stmt = $pdo->prepare("SELECT winners_data FROM raffles WHERE id = ? AND platform = ?");
    $stmt->execute([$raffleId, $platform]);
    $winnersData = $stmt->fetchColumn();

    if (!$winnersData) {
        return [];
    }

    $decoded = json_decode($winnersData, true);
    if (!is_array($decoded)) {
        return [];
    }

    $ids = [];
    foreach ($decoded as $row) {
        if (isset($row['user_id'])) {
            $ids[] = (int)$row['user_id'];
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

function get_broadcast_recipient_ids(PDO $pdo, string $filterType, ?string $raffleId, string $platform = 'max'): array {
    ensure_platform_columns($pdo);
    $platform = normalize_platform_value($platform);

    switch ($filterType) {
        case 'all_users':
            $stmt = $pdo->prepare("SELECT id FROM users WHERE platform = ? ORDER BY mess_date DESC");
            $stmt->execute([$platform]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_values(array_unique(array_map('intval', $rows ?: [])));

        case 'raffle_participants':
            if (!$raffleId) return [];
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.user_id
                FROM participants p
                INNER JOIN raffles r ON r.id = p.raffle_id
                WHERE p.raffle_id = ? AND r.platform = ?
                ORDER BY p.user_id ASC
            " );
            $stmt->execute([$raffleId, $platform]);
            return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        case 'raffle_winners':
            if (!$raffleId) return [];
            return get_broadcast_winner_ids($pdo, $raffleId, $platform);

        case 'raffle_non_winners':
            if (!$raffleId) return [];
            $stmt = $pdo->prepare("
                SELECT DISTINCT p.user_id
                FROM participants p
                INNER JOIN raffles r ON r.id = p.raffle_id
                WHERE p.raffle_id = ? AND r.platform = ?
                ORDER BY p.user_id ASC
            " );
            $stmt->execute([$raffleId, $platform]);
            $participants = array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            $winnerIds = get_broadcast_winner_ids($pdo, $raffleId, $platform);
            return array_values(array_diff($participants, $winnerIds));
    }

    return [];
}

function create_broadcast_with_queue(
    PDO $pdo,
    string $broadcastId,
    string $title,
    string $filterType,
    ?string $raffleId,
    string $messageText,
    ?string $mediaUrl,
    ?string $mediaType,
    ?string $mediaPayload,
    ?string $scheduledAt,
    array $recipientIds,
    string $platform = 'max'
): void {
    ensure_broadcast_tables($pdo);
    $platform = normalize_platform_value($platform);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO broadcasts (
            id, platform, title, filter_type, raffle_id, message_text, media_url, media_type, media_payload, scheduled_at, status, total_recipients, sent_count, failed_count, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 0, 0, NOW())");

        $stmt->execute([
            $broadcastId,
            $platform,
            $title,
            $filterType,
            $raffleId,
            $messageText,
            $mediaUrl,
            $mediaType,
            $mediaPayload,
            $scheduledAt,
            count($recipientIds),
        ]);

        if (!empty($recipientIds)) {
            $stmtRecipient = $pdo->prepare("INSERT IGNORE INTO broadcast_recipients (broadcast_id, user_id, status, created_at) VALUES (?, ?, 'pending', NOW())");
            foreach ($recipientIds as $userId) {
                $stmtRecipient->execute([$broadcastId, (int)$userId]);
            }
        } else {
            $pdo->prepare("UPDATE broadcasts SET status = 'done', finished_at = NOW() WHERE id = ?")->execute([$broadcastId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
