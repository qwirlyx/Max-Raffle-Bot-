<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';


function telegram_params_contain_file($value): bool {
    if ($value instanceof CURLFile) {
        return true;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            if (telegram_params_contain_file($item)) {
                return true;
            }
        }
    }
    return false;
}

function telegram_prepare_multipart_params(array $params): array {
    $prepared = [];
    foreach ($params as $key => $value) {
        if ($value === null) {
            continue;
        }
        if ($value instanceof CURLFile) {
            $prepared[$key] = $value;
        } elseif (is_array($value)) {
            $prepared[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_bool($value)) {
            $prepared[$key] = $value ? 'true' : 'false';
        } else {
            $prepared[$key] = (string)$value;
        }
    }
    return $prepared;
}

function telegram_media_input(string $urlOrPath) {
    if ($urlOrPath !== '' && is_file($urlOrPath) && is_readable($urlOrPath)) {
        return new CURLFile($urlOrPath);
    }
    return $urlOrPath;
}

function telegram_local_file_mime(string $filePath): string {
    if ($filePath === '' || !is_file($filePath)) {
        return '';
    }
    if (function_exists('mime_content_type')) {
        return (string)(mime_content_type($filePath) ?: '');
    }
    return '';
}

function telegram_prepare_photo_file(string $filePath): string {
    if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
        return $filePath;
    }

    $mime = telegram_local_file_mime($filePath);
    if (strpos($mime, 'image/') !== 0) {
        return $filePath;
    }

    if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        return $filePath;
    }

    $size = @getimagesize($filePath);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return $filePath;
    }

    $width = (int)$size[0];
    $height = (int)$size[1];
    if ($width <= 0 || $height <= 0) {
        return $filePath;
    }

    $cacheDir = __DIR__ . '/../data/telegram_photo_cache/';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        return $filePath;
    }

    $hash = sha1($filePath . '|' . filesize($filePath) . '|' . filemtime($filePath) . '|' . $width . 'x' . $height);
    $cachePath = $cacheDir . 'photo_' . $hash . '.jpg';
    if (is_file($cachePath) && filesize($cachePath) > 0) {
        return $cachePath;
    }

    $source = null;
    if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($filePath);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($filePath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($filePath);
    }

    if (!$source) {
        return $filePath;
    }

    // Telegram иногда отвергает крупные PNG/нестандартные изображения как PHOTO_INVALID_DIMENSIONS.
    // Для отправки как photo делаем нормальный JPEG с белым фоном и разумным размером.
    $maxSide = 1600;
    $ratio = min(1, $maxSide / max($width, $height));
    $newWidth = max(1, (int)round($width * $ratio));
    $newHeight = max(1, (int)round($height * $ratio));

    $canvas = @imagecreatetruecolor($newWidth, $newHeight);
    if (!$canvas) {
        imagedestroy($source);
        return $filePath;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    $saved = @imagejpeg($canvas, $cachePath, 90);
    imagedestroy($canvas);
    imagedestroy($source);

    if ($saved && is_file($cachePath) && filesize($cachePath) > 0) {
        return $cachePath;
    }

    return $filePath;
}

function telegram_photo_input(string $urlOrPath) {
    if ($urlOrPath !== '' && is_file($urlOrPath) && is_readable($urlOrPath)) {
        $urlOrPath = telegram_prepare_photo_file($urlOrPath);
    }
    return telegram_media_input($urlOrPath);
}

/**
 * Базовый запрос к Telegram Bot API.
 * Документация: https://core.telegram.org/bots/api
 */
function telegram_request(string $method, array $params = [], int $timeout = 20): array {
    if (!defined('TG_BOT_TOKEN') || trim((string)TG_BOT_TOKEN) === '') {
        return [
            'success' => false,
            'ok' => false,
            'message' => 'TG_BOT_TOKEN не заполнен в config.php',
        ];
    }

    $baseUrl = defined('TG_BOT_API_BASE_URL') ? rtrim(TG_BOT_API_BASE_URL, '/') : 'https://api.telegram.org/bot';
    $url = $baseUrl . TG_BOT_TOKEN . '/' . ltrim($method, '/');

    $hasFile = telegram_params_contain_file($params);
    $postFields = $hasFile
        ? telegram_prepare_multipart_params($params)
        : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
    ];

    if (!$hasFile) {
        $curlOptions[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $curlOptions);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $raw === null || $raw === '') {
        AppLogger::error('Telegram API empty response', [
            'method' => $method,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
        ]);

        return [
            'success' => false,
            'ok' => false,
            'message' => $curlError ?: 'Пустой ответ Telegram API',
            'http_code' => $httpCode,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        AppLogger::error('Telegram API invalid JSON', [
            'method' => $method,
            'http_code' => $httpCode,
            'raw' => $raw,
        ]);

        return [
            'success' => false,
            'ok' => false,
            'message' => 'Telegram API вернул не JSON',
            'http_code' => $httpCode,
            'raw' => $raw,
        ];
    }

    $decoded['success'] = !empty($decoded['ok']);
    $decoded['http_code'] = $httpCode;

    if (empty($decoded['ok'])) {
        AppLogger::error('Telegram API error', [
            'method' => $method,
            'http_code' => $httpCode,
            'response' => $decoded,
        ]);
    }

    return $decoded;
}

function telegram_get_me(): array {
    return telegram_request('getMe');
}

function telegram_set_webhook(string $webhookUrl, ?string $secretToken = null): array {
    $params = [
        'url' => $webhookUrl,
        'allowed_updates' => ['message', 'callback_query'],
    ];

    if ($secretToken !== null && $secretToken !== '') {
        $params['secret_token'] = $secretToken;
    }

    return telegram_request('setWebhook', $params);
}

function telegram_delete_webhook(): array {
    return telegram_request('deleteWebhook', ['drop_pending_updates' => true]);
}


function telegram_get_webhook_info(): array {
    return telegram_request('getWebhookInfo');
}

function telegram_get_chat($chatId): array {
    return telegram_request('getChat', [
        'chat_id' => $chatId,
    ]);
}

function telegram_send_test_message($chatId, string $text = 'Тестовое сообщение от бота розыгрышей'): array {
    return telegram_send_message($chatId, $text);
}


function telegram_send_message($chatId, string $text, ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('sendMessage', $params);
}

function telegram_send_photo($chatId, string $photoUrl, string $caption = '', ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'photo' => telegram_photo_input($photoUrl),
        'caption' => $caption,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('sendPhoto', $params);
}

function telegram_send_video($chatId, string $videoUrl, string $caption = '', ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'video' => telegram_media_input($videoUrl),
        'caption' => $caption,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('sendVideo', $params, 60);
}


function telegram_send_document($chatId, string $documentUrl, string $caption = '', ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'document' => telegram_media_input($documentUrl),
        'caption' => $caption,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('sendDocument', $params, 60);
}

function telegram_answer_callback_query(string $callbackQueryId, string $text = '', bool $showAlert = false): array {
    return telegram_request('answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => $showAlert,
    ]);
}

function telegram_check_membership($chatId, $userId): bool {
    $res = telegram_request('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
    ]);

    if (empty($res['ok']) || empty($res['result']['status'])) {
        return false;
    }

    return in_array($res['result']['status'], ['creator', 'administrator', 'member'], true);
}

function telegram_get_sent_message_id($res): ?int {
    if (isset($res['result']['message_id'])) {
        return (int)$res['result']['message_id'];
    }
    return null;
}

function telegram_inline_keyboard(array $rows): array {
    return ['inline_keyboard' => $rows];
}

function telegram_normalize_chat_input(string $input): string {
    $input = trim($input);
    if ($input === '') {
        return '';
    }

    if (preg_match('~^(https?://)?t\.me/([^/?#]+)~i', $input, $m)) {
        $slug = trim($m[2]);
        if ($slug !== '' && $slug[0] !== '+' && strtolower($slug) !== 'joinchat') {
            if ($slug[0] !== '@' && !preg_match('/^-?\d+$/', $slug)) {
                return '@' . $slug;
            }
            return $slug;
        }
        return $input;
    }

    if ($input[0] === '@' || preg_match('/^-?\d+$/', $input)) {
        return $input;
    }

    if (preg_match('/^[A-Za-z0-9_]{5,}$/', $input)) {
        return '@' . $input;
    }

    return $input;
}

function telegram_api_ok($res): bool {
    return is_array($res) && !empty($res['ok']);
}


function telegram_edit_message_text($chatId, $messageId, string $text, ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'message_id' => (int)$messageId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('editMessageText', $params);
}

function telegram_edit_message_caption($chatId, $messageId, string $caption, ?array $replyMarkup = null, array $extra = []): array {
    $params = array_merge([
        'chat_id' => $chatId,
        'message_id' => (int)$messageId,
        'caption' => $caption,
    ], $extra);

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    return telegram_request('editMessageCaption', $params);
}

function telegram_delete_message($chatId, $messageId): array {
    return telegram_request('deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => (int)$messageId,
    ]);
}

function telegram_get_chat_member_raw($chatId, $userId): array {
    return telegram_request('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $userId,
    ]);
}

function telegram_get_file(string $fileId): array {
    return telegram_request('getFile', [
        'file_id' => $fileId,
    ]);
}

function telegram_file_download_url(string $filePath): string {
    $baseUrl = defined('TG_BOT_API_BASE_URL') ? rtrim(TG_BOT_API_BASE_URL, '/') : 'https://api.telegram.org/bot';
    return $baseUrl . TG_BOT_TOKEN . '/file/' . ltrim($filePath, '/');
}

function telegram_get_bot_id(): ?int {
    $me = telegram_get_me();
    if (!empty($me['ok']) && isset($me['result']['id'])) {
        return (int)$me['result']['id'];
    }
    return null;
}

function telegram_check_bot_admin($chatId): array {
    $botId = telegram_get_bot_id();
    if (!$botId) {
        return ['ok' => false, 'success' => false, 'description' => 'Не удалось определить ID Telegram-бота через getMe'];
    }

    $member = telegram_get_chat_member_raw($chatId, $botId);
    if (empty($member['ok'])) {
        return $member;
    }

    $status = $member['result']['status'] ?? '';
    $member['is_admin'] = in_array($status, ['creator', 'administrator'], true);
    return $member;
}

?>
