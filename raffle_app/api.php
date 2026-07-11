<?php
// Отключаем вывод ошибок, чтобы не ломать JSON-ответ для Web App
ini_set('display_errors', 0);
error_reporting(0);

date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json');

$dbFile     = __DIR__ . '/../lib/db.php';
$apiFile    = __DIR__ . '/../lib/max_api.php';
$loggerFile = __DIR__ . '/../lib/logger.php';

if (!file_exists($dbFile) || !file_exists($apiFile)) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'System Error: Lib files not found']));
}

require_once $dbFile;
require_once $apiFile;
require_once $loggerFile;

$inputRaw = file_get_contents('php://input');
$input    = json_decode($inputRaw, true);

$userId   = $input['user_id']   ?? null;
$raffleId = $input['raffle_id'] ?? null;
$userName = $input['user_name'] ?? 'Аноним';

if (!$userId || !$raffleId) {
    AppLogger::warning('Запрос без user_id или raffle_id', [
        'user_id'   => $userId,
        'raffle_id' => $raffleId,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    echo json_encode(['success' => false, 'message' => 'Ошибка данных: нет ID']);
    exit;
}

try {
    $pdo = get_db_connection();

    // 1. Ищем розыгрыш
    $stmt = $pdo->prepare("SELECT * FROM raffles WHERE id = ?");
    $stmt->execute([$raffleId]);
    $raffle = $stmt->fetch();

    if (!$raffle) {
        AppLogger::warning('Розыгрыш не найден', ['raffle_id' => $raffleId, 'user_id' => $userId]);
        echo json_encode(['success' => false, 'message' => 'Розыгрыш не найден в базе']);
        exit;
    }

    if ($raffle['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Розыгрыш уже завершен 🏁']);
        exit;
    }

    // 2. ПРОВЕРКА ПОДПИСКИ
    if (!empty($raffle['check_subscription'])) {
        $isMember = max_check_membership($raffle['channel_id'], $userId);

        if ($isMember === null) {
            AppLogger::error('MAX API не ответил при проверке подписки', [
                'user_id'    => $userId,
                'channel_id' => $raffle['channel_id'],
                'raffle_id'  => $raffleId,
            ]);
            echo json_encode(['success' => false, 'message' => 'Не удалось проверить подписку. Попробуйте позже.']);
            exit;
        }

        if (!$isMember) {
            AppLogger::info('Отказ: нет подписки на канал', [
                'user_id'    => $userId,
                'channel_id' => $raffle['channel_id'],
            ]);
            echo json_encode([
                'success'     => false,
                'message'     => 'Нужна подписка на канал!',
                'require_sub' => true,
                'channel_id'  => $raffle['channel_id'],
            ]);
            exit;
        }
    }

    // 3. ПРОВЕРКА, УЧАСТВУЕТ ЛИ УЖЕ
    $stmtCheck = $pdo->prepare("SELECT 1 FROM participants WHERE raffle_id = ? AND user_id = ?");
    $stmtCheck->execute([$raffleId, $userId]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Ты уже участвуешь! 👌']);
        exit;
    }

    // 4. ГАРАНТИРУЕМ СУЩЕСТВОВАНИЕ ПОЛЬЗОВАТЕЛЯ В ТАБЛИЦЕ users
    // Пользователи, зашедшие через Web App напрямую (без /start боту),
    // не существуют в users — это ломает внешний ключ participants.user_id -> users.id
    //
    // PDO::ATTR_EMULATE_PREPARES = false запрещает повторять одно имя параметра
    // в одном запросе, поэтому используем позиционные ? вместо именованных.
    $now = time();
    $stmtUpsert = $pdo->prepare("
        INSERT INTO users (id, name, mess_date, unread, status)
        VALUES (?, ?, ?, 0, 'Участник розыгрыша')
        ON DUPLICATE KEY UPDATE
            name      = IF(? != '', ?, name),
            mess_date = GREATEST(mess_date, ?)
    ");
    $stmtUpsert->execute([$userId, $userName, $now, $userName, $userName, $now]);

    // 5. ЗАПИСЬ УЧАСТНИКА
    $stmtInsert = $pdo->prepare("INSERT INTO participants (raffle_id, user_id, name) VALUES (?, ?, ?)");
    $stmtInsert->execute([$raffleId, $userId, $userName]);

    AppLogger::info('Новый участник зарегистрирован', [
        'user_id'   => $userId,
        'user_name' => $userName,
        'raffle_id' => $raffleId,
    ]);

    echo json_encode(['success' => true, 'message' => 'Ура! Ты в списке участников! 🎉']);

} catch (Exception $e) {
    AppLogger::error('Исключение в raffle_app/api.php', [
        'user_id'   => $userId,
        'raffle_id' => $raffleId,
    ], $e);

    echo json_encode(['success' => false, 'message' => 'Внутренняя ошибка сервера. Попробуйте позже.']);
}
?>