<?php

require_once __DIR__ . '/../composer/vendor/autoload.php';

function normalize_codes_from_text($text) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $result = [];

    foreach ($lines as $line) {
        $code = trim($line);
        if ($code !== '') {
            $result[] = $code;
        }
    }

    return array_values(array_unique($result));
}

function normalize_single_code($value) {
    $code = trim((string)$value);

    if ($code === '') {
        return '';
    }

    $code = preg_replace('/\x{00A0}|\x{200B}|\x{200C}|\x{200D}|\x{FEFF}/u', '', $code);
    $code = trim($code);

    return $code;
}

function read_codes_from_csv_file($filePath, $delimiter = ',') {
    $codes = [];

    if (($handle = fopen($filePath, 'r')) !== false) {
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $value = $row[1] ?? '';
            $code = normalize_single_code($value);

            if ($code === '') {
                continue;
            }

            $lower = mb_strtolower($code);
            if (in_array($lower, ['промокод', 'promo', 'code', 'код', 'номера', 'строка', 'выдан'])) {
                continue;
            }

            $codes[] = $code;
        }
        fclose($handle);
    }

    return array_values(array_unique($codes));
}

function read_codes_from_xlsx_file($filePath) {
    $codes = [];

    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(true);

    $spreadsheet = $reader->load($filePath);
    $sheet = $spreadsheet->getSheet(0);

    $highestRow = $sheet->getHighestDataRow('B');

    for ($row = 1; $row <= $highestRow; $row++) {
        $value = $sheet->getCell('B' . $row)->getFormattedValue();
        $code = normalize_single_code($value);

        if ($code === '') {
            continue;
        }

        $lower = mb_strtolower($code);
        if (in_array($lower, ['промокод', 'promo', 'code', 'код', 'номера', 'строка', 'выдан'])) {
            continue;
        }

        $codes[] = $code;
    }

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return array_values(array_unique($codes));
}

function save_promocodes_to_raffle(PDO $pdo, string $raffleId, array $codes): array {
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));

    if (empty($codes)) {
        return ['total' => 0, 'added' => 0, 'skipped' => 0];
    }

    $stmtInsert = $pdo->prepare("
        INSERT IGNORE INTO raffle_promocodes (raffle_id, code, status, created_at)
        VALUES (?, ?, 'new', NOW())
    ");

    $added = 0;
    $total = count($codes);

    foreach ($codes as $code) {
        $stmtInsert->execute([$raffleId, $code]);
        $added += $stmtInsert->rowCount();
    }

    return [
        'total' => $total,
        'added' => $added,
        'skipped' => $total - $added
    ];
}