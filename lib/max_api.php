<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/logger.php';

ini_set('display_errors', 0);
error_reporting(0);

function max_check_membership($chatId, $userId) {
    $url = MAX_BOT_API_BASE_URL . "chats/$chatId/members";
    $query = http_build_query(['user_ids' => [$userId]]);
    $response = max_request($url . '?' . $query, [], 'GET');
    if ($response === null) {
        return null;
    }
    return isset($response['members']) && !empty($response['members']);
}

function max_send_message($recipient_id, $text, $attachments = null, $is_user = false) {
    if ($recipient_id === null || $recipient_id === '') {
        AppLogger::warning('max_send_message: пустой recipient_id');
        return ['code' => 'local_error', 'message' => 'Empty recipient_id'];
    }

    $url = MAX_BOT_API_BASE_URL . 'messages';
    $paramName = $is_user ? 'user_id' : 'chat_id';
    $query = http_build_query([$paramName => $recipient_id]);

    $payload = ['text' => (string)$text];
    if (!empty($attachments)) {
        $payload['attachments'] = $attachments;
    }

    return max_request($url . '?' . $query, $payload, 'POST');
}

function max_edit_message($message_id, $new_text) {
    $url = MAX_BOT_API_BASE_URL . 'messages?message_id=' . urlencode($message_id);
    $payload = [
        'text' => $new_text,
        'attachments' => null,
    ];
    return max_request($url, $payload, 'PUT');
}

function max_delete_message($message_id) {
    $url = MAX_BOT_API_BASE_URL . 'messages?message_id=' . urlencode($message_id);
    return max_request($url, [], 'DELETE');
}

function max_get_messages($target_id, $count = 50) {
    $url = MAX_BOT_API_BASE_URL . 'messages?chat_id=' . urlencode($target_id) . '&count=' . (int)$count;
    return max_request($url, [], 'GET');
}

function max_answer_callback($callbackId, $text) {
    $url = MAX_BOT_API_BASE_URL . 'answers';
    $payload = [
        'callback_id' => $callbackId,
        'notification' => $text,
    ];
    return max_request($url, $payload, 'POST');
}

function max_request($url, $data = [], $method = 'POST') {
    $ch = curl_init($url);
    $headers = [
        'Authorization: ' . BOT_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data !== null && $data !== [] && $data !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        AppLogger::error("MAX API cURL ошибка [$method]", [
            'url' => $url,
            'err' => $err,
        ]);
        return null;
    }

    $decoded = json_decode((string)$result, true);

    if ($httpCode >= 400) {
        AppLogger::warning("MAX API вернул HTTP $httpCode [$method]", [
            'url' => $url,
            'response' => $result,
        ]);
        $logFile = __DIR__ . '/../data/api_debug.log';
        $logData = date('Y-m-d H:i:s') . " [$method] $url ($httpCode)\nREQ: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\nRES: $result\n---\n";
        @file_put_contents($logFile, $logData, FILE_APPEND);
    }

    return is_array($decoded) ? $decoded : null;
}

function max_get_upload_url($type) {
    $type = in_array($type, ['image', 'video', 'audio', 'file'], true) ? $type : 'file';
    $url = MAX_BOT_API_BASE_URL . 'uploads?type=' . urlencode($type);
    $res = max_request($url, [], 'POST');

    if (!is_array($res) || empty($res['url'])) {
        AppLogger::warning('MAX upload init failed', [
            'type' => $type,
            'response' => $res,
        ]);
        return null;
    }

    return $res;
}

function max_upload_file_to_url($uploadUrl, $filePath) {
    if (!is_file($filePath)) {
        AppLogger::warning('max_upload_file_to_url: file not found', ['file_path' => $filePath]);
        return [
            'ok' => false,
            'code' => 'upload_local_file_missing',
            'message' => 'Local file not found',
        ];
    }

    $ch = curl_init($uploadUrl);

    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    $postFields = [
        'data' => new CURLFile($filePath, $mime, basename($filePath)),
    ];

    $headers = [
        'Accept: application/json, text/plain, */*',
        'Authorization: ' . BOT_TOKEN,
    ];

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        AppLogger::error('MAX upload curl error', [
            'upload_url' => $uploadUrl,
            'file_path' => $filePath,
            'err' => $err,
        ]);

        return [
            'ok' => false,
            'code' => 'upload_curl_error',
            'message' => $err,
        ];
    }

    $raw = trim((string)$result);
    $decoded = json_decode($raw, true);

    if ($httpCode >= 400) {
        AppLogger::warning("MAX upload returned HTTP $httpCode", [
            'upload_url' => $uploadUrl,
            'file_path' => $filePath,
            'response' => $raw,
        ]);

        return [
            'ok' => false,
            'code' => 'upload_http_error',
            'message' => $raw !== '' ? $raw : "HTTP $httpCode",
            'http_code' => $httpCode,
            'raw' => $raw,
            'json' => is_array($decoded) ? $decoded : null,
        ];
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function max_prepare_attachment_from_file($filePath, $attachmentType) {
    $attachmentType = in_array($attachmentType, ['image', 'video', 'audio', 'file'], true)
        ? $attachmentType
        : 'file';

    $uploadInit = max_get_upload_url($attachmentType);
    if (!$uploadInit || empty($uploadInit['url'])) {
        throw new Exception('MAX не вернул URL для загрузки медиа.');
    }

    $initToken = $uploadInit['token'] ?? null;

    $uploadResult = max_upload_file_to_url($uploadInit['url'], $filePath);

    if (!is_array($uploadResult) || empty($uploadResult['ok'])) {
        $message = is_array($uploadResult)
            ? ($uploadResult['message'] ?? $uploadResult['code'] ?? 'unknown upload error')
            : 'unknown upload error';

        throw new Exception('Не удалось загрузить медиа в MAX: ' . $message);
    }

    if ($attachmentType === 'video' || $attachmentType === 'audio') {
        if (empty($initToken)) {
            throw new Exception('MAX не вернул token для видео/аудио вложения.');
        }

        return [
            'type' => $attachmentType,
            'payload' => [
                'token' => $initToken,
            ],
        ];
    }

    $payload = $uploadResult['json'] ?? null;

    if (!is_array($payload) || empty($payload)) {
        $raw = $uploadResult['raw'] ?? '';
        throw new Exception('MAX вернул неожиданный ответ при загрузке файла: ' . ($raw !== '' ? $raw : 'empty response'));
    }

    return [
        'type' => $attachmentType,
        'payload' => $payload,
    ];
}
?>