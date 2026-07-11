<?php

require_once __DIR__ . '/../composer/vendor/autoload.php';

function gs_credentials_path() {
    return __DIR__ . '/../google_sheets/credentials.json';
}

function gs_extract_spreadsheet_id(string $input): string {
    $input = trim($input);

    if ($input === '') {
        throw new Exception('Пустая ссылка или ID таблицы.');
    }

    if (preg_match('~spreadsheets/d/([a-zA-Z0-9-_]+)~', $input, $m)) {
        return $m[1];
    }

    if (preg_match('~^[a-zA-Z0-9-_]{20,}$~', $input)) {
        return $input;
    }

    throw new Exception('Не удалось определить ID Google Таблицы из ссылки.');
}

function gs_client(): Google_Client {
    $credentials = gs_credentials_path();

    if (!file_exists($credentials)) {
        throw new Exception('Не найден credentials.json для Google Sheets.');
    }

    $client = new Google_Client();
    $client->setApplicationName('MaxRaffleBot Google Sheets');
    $client->setScopes([Google_Service_Sheets::SPREADSHEETS_READONLY]);
    $client->setAuthConfig($credentials);

    return $client;
}

function gs_service(): Google_Service_Sheets {
    return new Google_Service_Sheets(gs_client());
}

function gs_list_sheets(string $input): array {
    $spreadsheetId = gs_extract_spreadsheet_id($input);
    $service = gs_service();

    $spreadsheet = $service->spreadsheets->get(
        $spreadsheetId,
        ['fields' => 'sheets.properties.title']
    );

    $result = [];
    foreach ($spreadsheet->getSheets() as $sheet) {
        $title = $sheet->getProperties()->getTitle();
        if ($title !== '') {
            $result[] = $title;
        }
    }

    return $result;
}

function gs_read_promocodes(string $input, string $sheetName): array {
    $spreadsheetId = gs_extract_spreadsheet_id($input);
    $sheetName = trim($sheetName);

    if ($sheetName === '') {
        throw new Exception('Не выбрана страница таблицы.');
    }

    $safeSheetName = str_replace("'", "''", $sheetName);
    $range = "'{$safeSheetName}'!A:C";

    $service = gs_service();
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    $rows = $response->getValues();

    $codes = [];

    foreach ($rows as $row) {
        $value = trim((string)($row[1] ?? ''));

        if ($value === '') {
            continue;
        }

        $lower = mb_strtolower($value);
        if (in_array($lower, ['промокод', 'promo', 'code', 'код', 'номера', 'строка', 'выдан'])) {
            continue;
        }

        $codes[] = $value;
    }

    return array_values(array_unique($codes));
}