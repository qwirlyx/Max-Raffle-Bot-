<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../lib/google_sheets.php';

$response = ['sheets' => [], 'error' => null];

try {
    $input = trim($_GET['url'] ?? $_GET['id'] ?? '');

    if ($input === '') {
        throw new Exception('Не передана ссылка или ID таблицы.');
    }

    $response['sheets'] = gs_list_sheets($input);
} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);