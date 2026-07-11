<?php
require_once __DIR__ . "/session.php";
// ЗАЩИТА АДМИНКИ
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/db.php';

try {
    $pdo = get_db_connection();
    ensure_platform_columns($pdo);

    $platform = function_exists('normalize_platform') ? normalize_platform($_GET['platform'] ?? 'max') : ((($_GET['platform'] ?? 'max') === 'telegram') ? 'telegram' : 'max');

    // Параметры фильтрации и пагинации
    $search = trim($_GET['search'] ?? '');
    $raffle_id = $_GET['raffle_id'] ?? '';
    $winner_raffle_id = $_GET['winner_raffle_id'] ?? '';
    $limit = $_GET['limit'] ?? '10';

    $query = "SELECT id AS user_id, name, mess_date, unread, status, platform FROM users WHERE platform = ?";
    $params = [$platform];

    // Фильтр по строке (имя или ID)
    if ($search !== '') {
        $query .= " AND (name LIKE ? OR id LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    // Фильтр по ВСЕМ участникам конкретного розыгрыша, только выбранной площадки
    if ($raffle_id !== '') {
        $query .= " AND id IN (
            SELECT p.user_id
            FROM participants p
            INNER JOIN raffles r ON r.id = p.raffle_id
            WHERE p.raffle_id = ? AND r.platform = ?
        )";
        $params[] = $raffle_id;
        $params[] = $platform;
    }

    // Фильтр по ПОБЕДИТЕЛЯМ конкретного розыгрыша, только выбранной площадки
    if ($winner_raffle_id !== '') {
        $stmtW = $pdo->prepare("SELECT winners_data FROM raffles WHERE id = ? AND platform = ?");
        $stmtW->execute([$winner_raffle_id, $platform]);
        $wData = $stmtW->fetchColumn();
        
        $winnerIds = [];
        if ($wData) {
            $wArr = json_decode($wData, true);
            if (is_array($wArr)) {
                foreach ($wArr as $w) {
                    if (isset($w['user_id'])) {
                        $winnerIds[] = $w['user_id'];
                    }
                }
            }
        }
        
        if (empty($winnerIds)) {
            $query .= " AND 1=0"; 
        } else {
            $placeholders = implode(',', array_fill(0, count($winnerIds), '?'));
            $query .= " AND id IN ($placeholders)";
            $params = array_merge($params, $winnerIds);
        }
    }

    $query .= " ORDER BY mess_date DESC";

    if ($limit !== 'all') {
        $limitInt = (int)$limit;
        if ($limitInt > 0) {
            $query .= " LIMIT " . $limitInt;
        }
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($users ?: []);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>