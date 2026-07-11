<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

function vk_config_value(string $name, string $default = ''): string {
    return defined($name) ? trim((string)constant($name)) : $default;
}

function vk_api_version(): string {
    return vk_config_value('VK_API_VERSION', '5.199') ?: '5.199';
}

function vk_normalize_user_id($userId): string {
    $userId = trim((string)$userId);
    if (strpos($userId, 'vk_') === 0) {
        return substr($userId, 3);
    }
    return $userId;
}

function vk_chat_user_id($userId): string {
    $userId = vk_normalize_user_id($userId);
    return $userId !== '' ? 'vk_' . $userId : '';
}

function vk_request(string $method, array $params = [], int $timeout = 20, string $tokenConst = 'VK_GROUP_TOKEN'): array {
    $token = vk_config_value($tokenConst);
    if ($token === '') {
        return [
            'success' => false,
            'error' => ['error_msg' => $tokenConst . ' не заполнен в config.php'],
        ];
    }

    $params['access_token'] = $token;
    $params['v'] = vk_api_version();

    $url = 'https://api.vk.com/method/' . ltrim($method, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === null || $raw === '') {
        return [
            'success' => false,
            'error' => ['error_msg' => $curlError ?: 'Пустой ответ VK API'],
            'http_code' => $httpCode,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'error' => ['error_msg' => 'VK API вернул не JSON'],
            'raw' => $raw,
            'http_code' => $httpCode,
        ];
    }

    $decoded['success'] = empty($decoded['error']);
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function vk_get_group_info(): array {
    $groupId = vk_config_value('VK_GROUP_ID');
    if ($groupId === '') {
        return ['success' => false, 'error' => ['error_msg' => 'VK_GROUP_ID не заполнен в config.php']];
    }
    return vk_request('groups.getById', [
        'group_ids' => ltrim($groupId, '-'),
        'fields' => 'description,screen_name,members_count',
    ]);
}

function vk_get_user_info($userId): array {
    $id = vk_normalize_user_id($userId);
    if ($id === '') {
        return ['success' => false, 'error' => ['error_msg' => 'Пустой VK user_id']];
    }
    return vk_request('users.get', [
        'user_ids' => $id,
        'fields' => 'photo_50,screen_name',
    ]);
}

function vk_get_user_display_name($userId): string {
    $res = vk_get_user_info($userId);
    $user = $res['response'][0] ?? null;
    if (is_array($user)) {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($name !== '') return $name;
        if (!empty($user['screen_name'])) return '@' . $user['screen_name'];
    }
    return 'VK ID: ' . vk_normalize_user_id($userId);
}

function vk_send_message($userId, string $text, array $extra = []): array {
    $peerId = vk_normalize_user_id($userId);
    $text = trim($text);
    if ($peerId === '') {
        return ['success' => false, 'error' => ['error_msg' => 'Пустой VK user_id']];
    }
    if ($text === '') {
        return ['success' => false, 'error' => ['error_msg' => 'Пустое сообщение']];
    }

    $params = array_merge([
        'user_id' => $peerId,
        'random_id' => random_int(1, PHP_INT_MAX),
        'message' => $text,
    ], $extra);

    return vk_request('messages.send', $params);
}

function vk_send_test_message($userId, string $text = 'Тестовое сообщение от бота розыгрышей VK'): array {
    return vk_send_message($userId, $text);
}

function vk_group_id_from_channel($channelId = null): string {
    $value = trim((string)($channelId ?? ''));
    if ($value === '' && defined('VK_GROUP_ID')) {
        $value = (string)VK_GROUP_ID;
    }
    $value = trim($value);
    if (strpos($value, 'vk_') === 0) {
        $value = substr($value, 3);
    }
    return ltrim($value, '-');
}

function vk_extract_local_upload_path(?string $mediaUrl): ?string {
    $mediaUrl = trim((string)$mediaUrl);
    if ($mediaUrl === '' || !defined('PROJECT_URL')) {
        return null;
    }

    $baseUrl = rtrim((string)PROJECT_URL, '/');
    if (strpos($mediaUrl, $baseUrl . '/uploads/') !== 0) {
        return null;
    }

    $relativePath = substr($mediaUrl, strlen($baseUrl));
    $fullPath = realpath(__DIR__ . '/..' . $relativePath);
    $uploadsRoot = realpath(__DIR__ . '/../uploads');

    if (!$fullPath || !$uploadsRoot || strpos($fullPath, $uploadsRoot) !== 0 || !is_file($fullPath)) {
        return null;
    }

    return $fullPath;
}

function vk_is_image_file(string $path): bool {
    $mime = mime_content_type($path) ?: '';
    return strpos($mime, 'image/') === 0;
}

function vk_upload_file_to_url(string $uploadUrl, string $filePath, string $fieldName = 'photo'): array {
    if (!is_file($filePath)) {
        return ['success' => false, 'error' => ['error_msg' => 'Файл не найден: ' . $filePath]];
    }

    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [$fieldName => new CURLFile($filePath)],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 40,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === null || $raw === '') {
        return ['success' => false, 'error' => ['error_msg' => $curlError ?: 'Пустой ответ upload-сервера VK'], 'http_code' => $httpCode];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => ['error_msg' => 'Upload-сервер VK вернул не JSON'], 'raw' => $raw, 'http_code' => $httpCode];
    }

    $decoded['success'] = empty($decoded['error']);
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function vk_prepare_wall_photo_attachment(?string $mediaUrl, $channelId = null): ?string {
    $filePath = vk_extract_local_upload_path($mediaUrl);
    if (!$filePath || !vk_is_image_file($filePath)) {
        return null;
    }

    $groupId = vk_group_id_from_channel($channelId);
    if ($groupId === '') {
        return null;
    }

    // Для настоящей картинки в посте VK нужен пользовательский токен админа
    // с правами wall/photos. Токен сообщества не может вызвать photos.getWallUploadServer.
    $photoTokenConst = vk_config_value('VK_USER_TOKEN') !== '' ? 'VK_USER_TOKEN' : 'VK_GROUP_TOKEN';
    $server = vk_request('photos.getWallUploadServer', ['group_id' => $groupId], 20, $photoTokenConst);
    if (empty($server['success']) || empty($server['response']['upload_url'])) {
        AppLogger::error('VK wall photo upload server failed', ['response' => $server, 'token' => $photoTokenConst]);
        return null;
    }

    $uploaded = vk_upload_file_to_url($server['response']['upload_url'], $filePath, 'photo');
    if (empty($uploaded['success'])) {
        AppLogger::error('VK wall photo upload failed', ['response' => $uploaded]);
        return null;
    }

    $saved = vk_request('photos.saveWallPhoto', [
        'group_id' => $groupId,
        'photo' => $uploaded['photo'] ?? '',
        'server' => $uploaded['server'] ?? '',
        'hash' => $uploaded['hash'] ?? '',
    ], 20, $photoTokenConst);

    if (empty($saved['success']) || empty($saved['response'][0]['owner_id']) || empty($saved['response'][0]['id'])) {
        AppLogger::error('VK save wall photo failed', ['response' => $saved, 'token' => $photoTokenConst]);
        return null;
    }

    $photo = $saved['response'][0];
    return 'photo' . $photo['owner_id'] . '_' . $photo['id'];
}

function vk_wall_post($channelId, string $message, ?string $mediaUrl = null): array {
    $groupId = vk_group_id_from_channel($channelId);
    $message = trim($message);

    if ($groupId === '') {
        return ['success' => false, 'error' => ['error_msg' => 'VK_GROUP_ID не заполнен или канал VK не выбран']];
    }
    if ($message === '') {
        return ['success' => false, 'error' => ['error_msg' => 'Пустой текст VK-поста']];
    }

    $params = [
        'owner_id' => '-' . $groupId,
        'from_group' => 1,
        'message' => $message,
    ];

    $attachment = vk_prepare_wall_photo_attachment($mediaUrl, $channelId);
    if ($attachment) {
        $params['attachments'] = $attachment;
    } elseif (trim((string)$mediaUrl) !== '') {
        $params['message'] .= "

Изображение к розыгрышу: " . trim((string)$mediaUrl);
    }

    return vk_request('wall.post', $params, 40);
}

function vk_wall_post_ref(array $response, $channelId = null): ?string {
    if (empty($response['success']) || empty($response['response']['post_id'])) {
        return null;
    }
    $groupId = vk_group_id_from_channel($channelId);
    if ($groupId === '') {
        return (string)$response['response']['post_id'];
    }
    return 'wall-' . $groupId . '_' . $response['response']['post_id'];
}


function vk_parse_wall_ref(string $wallRef, $channelId = null): ?array {
    $wallRef = trim($wallRef);
    if (preg_match('~wall(-?\d+)_(\d+)~', $wallRef, $m)) {
        return [
            'owner_id' => (int)$m[1],
            'post_id' => (int)$m[2],
            'group_id' => ltrim((string)$m[1], '-'),
        ];
    }

    $groupId = vk_group_id_from_channel($channelId);
    if ($groupId !== '' && ctype_digit($wallRef)) {
        return [
            'owner_id' => -(int)$groupId,
            'post_id' => (int)$wallRef,
            'group_id' => $groupId,
        ];
    }

    return null;
}

function vk_get_wall_comments_all(string $wallRef, $channelId = null, int $max = 1000): array {
    $ref = vk_parse_wall_ref($wallRef, $channelId);
    if (!$ref) {
        return ['success' => false, 'error' => ['error_msg' => 'Не удалось разобрать VK post ref: ' . $wallRef], 'items' => []];
    }

    $items = [];
    $offset = 0;
    $count = 100;

    while ($offset < $max) {
        $res = vk_request('wall.getComments', [
            'owner_id' => $ref['owner_id'],
            'post_id' => $ref['post_id'],
            'count' => $count,
            'offset' => $offset,
            'sort' => 'asc',
            'need_likes' => 1,
            'preview_length' => 0,
            'thread_items_count' => 0,
        ], 40);

        if (empty($res['success'])) {
            $res['items'] = $items;
            return $res;
        }

        $batch = $res['response']['items'] ?? [];
        if (!is_array($batch) || empty($batch)) {
            break;
        }

        foreach ($batch as $comment) {
            if (is_array($comment)) {
                $items[] = $comment;
            }
        }

        if (count($batch) < $count) {
            break;
        }

        $offset += $count;
    }

    return ['success' => true, 'items' => $items, 'response' => ['count' => count($items)]];
}

function vk_is_group_member($userId, $groupId = null): bool {
    $uid = vk_normalize_user_id($userId);
    $gid = $groupId !== null ? ltrim(trim((string)$groupId), '-') : vk_group_id_from_channel(null);
    if ($uid === '' || $gid === '') return false;

    $res = vk_request('groups.isMember', [
        'group_id' => $gid,
        'user_id' => $uid,
    ]);

    if (isset($res['response'])) {
        return (int)$res['response'] === 1;
    }
    return false;
}

function vk_user_liked_post(string $wallRef, $userId, $channelId = null): bool {
    $ref = vk_parse_wall_ref($wallRef, $channelId);
    $uid = vk_normalize_user_id($userId);
    if (!$ref || $uid === '') return false;

    $res = vk_request('likes.isLiked', [
        'user_id' => $uid,
        'type' => 'post',
        'owner_id' => $ref['owner_id'],
        'item_id' => $ref['post_id'],
    ]);

    return !empty($res['response']['liked']);
}

function vk_comment_extract_number(string $text, ?int $min = null, ?int $max = null): ?int {
    if (!preg_match('/(?<!\d)-?\d+(?!\d)/u', $text, $m)) return null;
    $number = (int)$m[0];
    if ($min !== null && $number < $min) return null;
    if ($max !== null && $number > $max) return null;
    return $number;
}

function vk_raffle_comment_matches_word(string $commentText, string $word, bool $caseSensitive): bool {
    $commentText = trim($commentText);
    $word = trim($word);
    if ($word === '') return $commentText !== '';
    if ($caseSensitive) return mb_strpos($commentText, $word, 0, 'UTF-8') !== false;
    return mb_stripos($commentText, $word, 0, 'UTF-8') !== false;
}

function vk_raffle_user_has_bot_dialog(PDO $pdo, $vkUserId): bool {
    $chatUserId = vk_chat_user_id($vkUserId);
    if ($chatUserId === '') return false;
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND platform = 'vk' LIMIT 1");
    $stmt->execute([$chatUserId]);
    return (bool)$stmt->fetchColumn();
}

function vk_raffle_ensure_user_row(PDO $pdo, string $chatUserId, string $name): void {
    $time = time();
    $stmt = $pdo->prepare("\n        INSERT INTO users (id, platform, name, mess_date, unread, status)\n        VALUES (?, 'vk', ?, ?, 0, 'VK участник')\n        ON DUPLICATE KEY UPDATE name = IF(name IS NULL OR name = '' OR name LIKE 'VK ID:%', VALUES(name), name), platform = 'vk'\n    ");
    $stmt->execute([$chatUserId, $name, $time]);
}

function vk_save_comment_event(PDO $pdo, string $raffleId, int $ownerId, int $postId, int $commentId, int $fromId, string $text, ?int $createdAt = null): void {
    if ($raffleId === '' || $commentId <= 0 || $fromId <= 0) return;
    $chatUserId = vk_chat_user_id($fromId);
    if ($chatUserId === '') return;
    $stmt = $pdo->prepare("\n        INSERT INTO vk_comment_events (raffle_id, owner_id, post_id, comment_id, user_id, vk_user_id, text, created_at)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE text = VALUES(text), created_at = VALUES(created_at), raffle_id = VALUES(raffle_id), user_id = VALUES(user_id), vk_user_id = VALUES(vk_user_id)\n    ");
    $stmt->execute([$raffleId, $ownerId, $postId, $commentId, $chatUserId, $fromId, $text, $createdAt ?: time()]);
}

function vk_find_raffle_by_wall(PDO $pdo, int $ownerId, int $postId): ?array {
    $postRef = 'wall' . $ownerId . '_' . $postId;
    $stmt = $pdo->prepare("SELECT * FROM raffles WHERE platform = 'vk' AND post_message_id = ? AND status IN ('active','pending','processing') LIMIT 1");
    $stmt->execute([$postRef]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function vk_comment_event_meta_for_raffle(array $raffle, string $commentText, ?int $commentId = null): array {
    $mode = (string)($raffle['vk_comment_mode'] ?? 'word');
    $meta = ['comment_text' => $commentText];
    if ($commentId !== null) $meta['comment_id'] = $commentId;

    if ($mode === 'number') {
        $min = isset($raffle['vk_number_min']) && $raffle['vk_number_min'] !== null && $raffle['vk_number_min'] !== '' ? (int)$raffle['vk_number_min'] : null;
        $max = isset($raffle['vk_number_max']) && $raffle['vk_number_max'] !== null && $raffle['vk_number_max'] !== '' ? (int)$raffle['vk_number_max'] : null;
        $number = vk_comment_extract_number($commentText, $min, $max);
        if ($number === null) return ['ok' => false, 'reason' => 'comment_number', 'meta' => $meta];
        $meta['comment_number'] = $number;
        return ['ok' => true, 'reason' => null, 'meta' => $meta];
    }

    if (!empty($raffle['vk_require_comment']) && !vk_raffle_comment_matches_word($commentText, (string)($raffle['vk_comment_word'] ?? ''), !empty($raffle['vk_case_sensitive']))) {
        return ['ok' => false, 'reason' => 'comment_word', 'meta' => $meta];
    }
    return ['ok' => true, 'reason' => null, 'meta' => $meta];
}

function vk_validate_comment_event(PDO $pdo, array $raffle, array $event): array {
    $fromId = (int)($event['vk_user_id'] ?? 0);
    if ($fromId <= 0) return ['ok' => false, 'reason' => 'not_user'];

    $commentCheck = vk_comment_event_meta_for_raffle($raffle, (string)($event['text'] ?? ''), isset($event['comment_id']) ? (int)$event['comment_id'] : null);
    if (empty($commentCheck['ok'])) return $commentCheck;

    $groupId = vk_group_id_from_channel($raffle['channel_id'] ?? null);
    if (!empty($raffle['check_subscription']) && !vk_is_group_member($fromId, $groupId)) {
        return ['ok' => false, 'reason' => 'subscription', 'meta' => $commentCheck['meta']];
    }
    if (!empty($raffle['vk_require_like']) && !vk_user_liked_post((string)($raffle['post_message_id'] ?? ''), $fromId, $raffle['channel_id'] ?? null)) {
        return ['ok' => false, 'reason' => 'like', 'meta' => $commentCheck['meta']];
    }
    if (!empty($raffle['vk_require_bot_message']) && !vk_raffle_user_has_bot_dialog($pdo, $fromId)) {
        return ['ok' => false, 'reason' => 'bot_message', 'meta' => $commentCheck['meta']];
    }
    return ['ok' => true, 'reason' => null, 'meta' => $commentCheck['meta']];
}

function vk_add_participant_from_event(PDO $pdo, string $raffleId, $vkUserId, string $name, array $meta = []): bool {
    $chatUserId = vk_chat_user_id($vkUserId);
    if ($chatUserId === '') return false;
    vk_raffle_ensure_user_row($pdo, $chatUserId, $name);
    $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmtCheck = $pdo->prepare("SELECT 1 FROM participants WHERE raffle_id = ? AND user_id = ? LIMIT 1");
    $stmtCheck->execute([$raffleId, $chatUserId]);
    if ($stmtCheck->fetchColumn()) {
        if ($metaJson !== null) {
            try { $pdo->prepare("UPDATE participants SET meta = COALESCE(meta, ?) WHERE raffle_id = ? AND user_id = ?")->execute([$metaJson, $raffleId, $chatUserId]); } catch (Exception $e) {}
        }
        return false;
    }
    try {
        $pdo->prepare("INSERT INTO participants (raffle_id, user_id, name, meta) VALUES (?, ?, ?, ?)")->execute([$raffleId, $chatUserId, $name, $metaJson]);
    } catch (Exception $e) {
        $pdo->prepare("INSERT INTO participants (raffle_id, user_id, name) VALUES (?, ?, ?)")->execute([$raffleId, $chatUserId, $name]);
    }
    return true;
}

function vk_process_comment_events_for_raffle(PDO $pdo, array $raffle, ?string $logFile = null): array {
    $stats = ['added' => 0, 'accepted' => 0, 'checked' => 0, 'skipped' => []];
    $stmt = $pdo->prepare("SELECT * FROM vk_comment_events WHERE raffle_id = ? ORDER BY created_at ASC, id ASC");
    $stmt->execute([(string)$raffle['id']]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seenUsers = [];
    foreach ($events as $event) {
        $stats['checked']++;
        $vkUserId = (int)($event['vk_user_id'] ?? 0);
        if ($vkUserId <= 0) { $stats['skipped']['not_user'] = ($stats['skipped']['not_user'] ?? 0) + 1; continue; }
        if (isset($seenUsers[$vkUserId])) { $stats['skipped']['duplicate_comment'] = ($stats['skipped']['duplicate_comment'] ?? 0) + 1; continue; }
        $validation = vk_validate_comment_event($pdo, $raffle, $event);
        $reason = $validation['reason'] ?? null;
        $meta = $validation['meta'] ?? [];
        $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $pdo->prepare("UPDATE vk_comment_events SET accepted = ?, skip_reason = ?, meta = ? WHERE id = ?")->execute([!empty($validation['ok']) ? 1 : 0, $reason, $metaJson, $event['id']]);
        if (empty($validation['ok'])) { $stats['skipped'][$reason ?: 'unknown'] = ($stats['skipped'][$reason ?: 'unknown'] ?? 0) + 1; continue; }
        $seenUsers[$vkUserId] = true;
        $name = vk_get_user_display_name($vkUserId);
        $stats['accepted']++;
        if (vk_add_participant_from_event($pdo, (string)$raffle['id'], $vkUserId, $name, $meta)) $stats['added']++;
    }
    if ($logFile !== null) @file_put_contents($logFile, date('Y-m-d H:i:s') . " ✅ VK stored comments collect {$raffle['id']}: " . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return $stats;
}

function vk_get_sent_message_id(array $response): ?string {
    if (isset($response['response'])) return (string)$response['response'];
    if (isset($response['message_id'])) return (string)$response['message_id'];
    return null;
}

function vk_response_error_text($res): ?string {
    if (!is_array($res)) {
        return 'VK API returned non-array/null response';
    }
    if (!empty($res['success'])) {
        return null;
    }
    return $res['error']['error_msg'] ?? $res['message'] ?? 'VK send failed';
}

?>
