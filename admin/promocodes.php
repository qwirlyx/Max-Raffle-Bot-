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
ini_set('memory_limit', '512M');
set_time_limit(120);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/google_sheets.php';
require_once __DIR__ . '/../composer/vendor/autoload.php';

$pdo = get_db_connection();

$raffleId = $_GET['raffle_id'] ?? $_POST['raffle_id'] ?? '';
if (!$raffleId) {
    header('Location: index.php?tab=List');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ?");
$stmt->execute([$raffleId]);
$raffle = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$raffle) {
    exit('Розыгрыш не найден');
}

$statusMsg = '';

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

    // Убираем невидимые пробелы
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'upload_codes') {
        $codes = [];

        if (!empty($_POST['codes_text'])) {
            $codes = array_merge($codes, normalize_codes_from_text($_POST['codes_text']));
        }

        if (isset($_FILES['codes_file']) && $_FILES['codes_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['codes_file']['tmp_name'];
            $originalName = $_FILES['codes_file']['name'] ?? '';
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ($ext === 'txt') {
                $fileText = file_get_contents($tmpPath);
                $codes = array_merge($codes, normalize_codes_from_text($fileText));
            } elseif ($ext === 'csv') {
                $codes = array_merge($codes, read_codes_from_csv_file($tmpPath, ','));
            } elseif ($ext === 'xlsx') {
                $codes = array_merge($codes, read_codes_from_xlsx_file($tmpPath));
            } else {
                $statusMsg = '❌ Неподдерживаемый формат файла. Разрешены: TXT, CSV, XLSX';
            }
        }

        $codes = array_values(array_unique($codes));

        if (empty($codes)) {
            $statusMsg = '❌ Нет кодов для загрузки';
        } else {
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

            $skipped = $total - $added;

            if ($added > 0 && $skipped > 0) {
                $statusMsg = "✅ Загружено новых кодов: {$added}. Пропущено дублей: {$skipped}";
            } elseif ($added > 0) {
                $statusMsg = "✅ Загружено кодов: {$added}";
            } else {
                $statusMsg = "⚠️ Новых кодов не добавлено. Либо файл пустой, либо все коды уже есть в этом розыгрыше.";
            }
        }
    }

    if ($_POST['action'] === 'import_google_sheet_codes') {
        $googleSheetUrl = trim($_POST['google_sheet_url'] ?? '');
        $googleSheetName = trim($_POST['google_sheet_name'] ?? '');

        try {
            $codes = gs_read_promocodes($googleSheetUrl, $googleSheetName);

            if (empty($codes)) {
                $statusMsg = '❌ На выбранной странице не найдено промокодов.';
            } else {
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

                $skipped = $total - $added;

                if ($added > 0 && $skipped > 0) {
                    $statusMsg = "✅ Из Google Таблицы считано: {$total}. Добавлено новых: {$added}. Пропущено дублей: {$skipped}";
                } elseif ($added > 0) {
                    $statusMsg = "✅ Из Google Таблицы загружено кодов: {$added}";
                } else {
                    $statusMsg = "⚠️ Все коды с выбранной страницы уже есть в этом розыгрыше.";
                }
            }
        } catch (Throwable $e) {
            $statusMsg = '❌ Ошибка Google Sheets: ' . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'clear_all_codes') {
        $stmtClear = $pdo->prepare("
            DELETE FROM raffle_promocodes
            WHERE raffle_id = ?
        ");
        $stmtClear->execute([$raffleId]);

        $deletedCount = $stmtClear->rowCount();
        $statusMsg = "🧹 Удалено промокодов: {$deletedCount}";
    }

    if ($_POST['action'] === 'delete_code' && !empty($_POST['code_id'])) {
        $stmtDel = $pdo->prepare("
            DELETE FROM raffle_promocodes
            WHERE id = ? AND raffle_id = ? AND status = 'new'
        ");
        $stmtDel->execute([(int)$_POST['code_id'], $raffleId]);
        $statusMsg = $stmtDel->rowCount() ? '🗑 Код удален' : '❌ Удалить можно только свободный код';
    }
}

$stmtStats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'new') AS new_count,
        SUM(status = 'assigned') AS assigned_count,
        SUM(status = 'sent') AS sent_count,
        SUM(status = 'failed') AS failed_count
    FROM raffle_promocodes
    WHERE raffle_id = ?
");
$stmtStats->execute([$raffleId]);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$stmtFree = $pdo->prepare("
    SELECT * FROM raffle_promocodes
    WHERE raffle_id = ? AND status = 'new'
    ORDER BY id ASC
");
$stmtFree->execute([$raffleId]);
$freeCodes = $stmtFree->fetchAll(PDO::FETCH_ASSOC);

$stmtIssued = $pdo->prepare("
    SELECT * FROM raffle_promocodes
    WHERE raffle_id = ? AND status IN ('assigned','sent','failed')
    ORDER BY id DESC
");
$stmtIssued->execute([$raffleId]);
$issuedCodes = $stmtIssued->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Промокоды — <?= htmlspecialchars($raffle['title']) ?></title>
    <link rel="icon" type="image/x-icon" href="../icon.ico">
    <link rel="stylesheet" href="../styles.css?v=2">
    <style>
        body { background:#121212; color:#fff; font-family:Arial,sans-serif; }
        .wrap { max-width:1100px; margin:20px auto; padding:20px; }
        .card { background:#1e1e1e; border:1px solid #333; border-radius:10px; padding:20px; margin-bottom:20px; }
        textarea, input[type=file] { width:100%; margin-top:10px; }
        table { width:100%; border-collapse:collapse; margin-top:10px; }
        th, td { border:1px solid #333; padding:8px; text-align:left; vertical-align:top; }
        th { background:#252525; }
        .top-links { display:flex; gap:10px; margin-bottom:20px; }
        .btn { display:inline-block; background:#4a90e2; color:#fff; padding:10px 14px; border:none; border-radius:6px; text-decoration:none; cursor:pointer; }
        .btn-danger { background:#d32f2f; }
        .muted { color:#999; font-size:13px; }
    </style>
</head>

<script>
async function loadGoogleSheets() {
    const input = document.getElementById('google_sheet_url');
    const select = document.getElementById('google_sheet_name');
    const hidden = document.getElementById('google_sheet_url_hidden');

    const url = input.value.trim();
    if (!url) {
        alert('Вставь ссылку на Google Таблицу');
        return;
    }

    hidden.value = url;
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

function syncGoogleSheetUrlBeforeSubmit() {
    const url = document.getElementById('google_sheet_url').value.trim();
    document.getElementById('google_sheet_url_hidden').value = url;

    if (!url) {
        alert('Вставь ссылку на Google Таблицу');
        return false;
    }

    const sheet = document.getElementById('google_sheet_name').value;
    if (!sheet) {
        alert('Выбери страницу таблицы');
        return false;
    }

    return true;
}
</script>

<body>
<div class="wrap">
    <div class="top-links">
        <a class="btn" href="index.php?tab=List">← Назад к списку</a>
    </div>

    <div class="card">
        <h2>Промокоды: <?= htmlspecialchars($raffle['title']) ?></h2>
        <div class="muted">ID розыгрыша: <?= htmlspecialchars($raffle['id']) ?></div>
        <?php if ($statusMsg): ?>
            <p><?= $statusMsg ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Статистика</h3>
        <p>
            Всего: <b><?= (int)$stats['total'] ?></b> |
            Свободных: <b><?= (int)$stats['new_count'] ?></b> |
            Закреплено: <b><?= (int)$stats['assigned_count'] ?></b> |
            Отправлено: <b><?= (int)$stats['sent_count'] ?></b> |
            Ошибки: <b><?= (int)$stats['failed_count'] ?></b>
        </p>
    </div>

    <div class="card">
        <h3>Загрузка кодов</h3>
    
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_codes">
            <input type="hidden" name="raffle_id" value="<?= htmlspecialchars($raffleId) ?>">
    
            <label>Текстовое поле (1 код = 1 строка)</label>
            <textarea name="codes_text" rows="10" placeholder="PROMO-111&#10;PROMO-222&#10;PROMO-333"></textarea>
    
            <br><br>
    
            <label>Файл TXT / CSV / XLSX</label>
            <input type="file" name="codes_file" accept=".txt,.csv,.xlsx">
    
            <br><br>
            <button type="submit" class="btn">Загрузить коды</button>
        </form>
    
        <br>
    
        <form method="POST" onsubmit="return confirm('Удалить ВСЕ промокоды этого розыгрыша? Это действие нельзя отменить.');">
            <input type="hidden" name="action" value="clear_all_codes">
            <input type="hidden" name="raffle_id" value="<?= htmlspecialchars($raffleId) ?>">
            <button type="submit" class="btn btn-danger">🧹 Очистить все промокоды</button>
        </form>
    </div>
    
    <div class="card">
        <h3>Импорт из Google Таблицы</h3>
    
        <div style="margin-bottom:10px;">
            <label>Ссылка на Google Таблицу</label>
            <input type="text" id="google_sheet_url" name="google_sheet_url_input" placeholder="https://docs.google.com/spreadsheets/d/.../edit" style="width:100%; margin-top:10px;">
        </div>
    
        <div style="margin-bottom:10px;">
            <button type="button" class="btn" onclick="loadGoogleSheets()">Загрузить страницы</button>
        </div>
    
        <form method="POST">
            <input type="hidden" name="action" value="import_google_sheet_codes">
            <input type="hidden" name="raffle_id" value="<?= htmlspecialchars($raffleId) ?>">
            <input type="hidden" name="google_sheet_url" id="google_sheet_url_hidden">
    
            <label>Страница с промокодами</label>
            <select name="google_sheet_name" id="google_sheet_name" style="width:100%; margin-top:10px; padding:10px; background:#1e1e1e; color:#fff; border:1px solid #333; border-radius:6px;">
                <option value="">Сначала загрузите страницы</option>
            </select>
    
            <br><br>
            <button type="submit" class="btn" onclick="return syncGoogleSheetUrlBeforeSubmit()">Импортировать промокоды</button>
        </form>
    </div>

    <div class="card">
        <h3>Свободные коды</h3>
        <?php if (empty($freeCodes)): ?>
            <p class="muted">Нет свободных кодов</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Код</th>
                    <th>Действие</th>
                </tr>
                <?php foreach ($freeCodes as $code): ?>
                    <tr>
                        <td><?= (int)$code['id'] ?></td>
                        <td><?= htmlspecialchars($code['code']) ?></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Удалить этот код?');">
                                <input type="hidden" name="action" value="delete_code">
                                <input type="hidden" name="raffle_id" value="<?= htmlspecialchars($raffleId) ?>">
                                <input type="hidden" name="code_id" value="<?= (int)$code['id'] ?>">
                                <button type="submit" class="btn btn-danger">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>Выданные / проблемные коды</h3>
        <?php if (empty($issuedCodes)): ?>
            <p class="muted">Пока нет</p>
        <?php else: ?>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Код</th>
                    <th>Статус</th>
                    <th>Пользователь</th>
                    <th>Имя</th>
                    <th>Когда</th>
                    <th>Ошибка</th>
                </tr>
                <?php foreach ($issuedCodes as $code): ?>
                    <tr>
                        <td><?= (int)$code['id'] ?></td>
                        <td><?= htmlspecialchars($code['code']) ?></td>
                        <td><?= htmlspecialchars($code['status']) ?></td>
                        <td><?= htmlspecialchars((string)$code['assigned_to_user_id']) ?></td>
                        <td><?= htmlspecialchars((string)$code['assigned_to_name']) ?></td>
                        <td><?= htmlspecialchars((string)($code['sent_at'] ?: $code['assigned_at'])) ?></td>
                        <td><?= htmlspecialchars((string)$code['error_text']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>