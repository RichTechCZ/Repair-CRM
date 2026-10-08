<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

$tech_id = $_GET['tech_id'] ?? null;
$type = $_GET['type'] ?? '';

// Security: If not admin, force tech_id to current user's tech_id
if (!hasPermission('admin_access') && ($_SESSION['role'] ?? '') == 'technician') {
    $tech_id = $_SESSION['tech_id'];
} elseif (!hasPermission('admin_access')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

$startDateRaw = (string)($_GET['start_date'] ?? date('Y-m-01'));
$endDateRaw = (string)($_GET['end_date'] ?? date('Y-m-t'));
$startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startDateRaw);
$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $endDateRaw);
if (
    $startDate === false ||
    $endDate === false ||
    $startDate->format('Y-m-d') !== $startDateRaw ||
    $endDate->format('Y-m-d') !== $endDateRaw ||
    $endDate < $startDate ||
    $startDate->diff($endDate)->days > 366
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid report date range']);
    exit;
}
$start = $startDateRaw . ' 00:00:00';
$end = $endDateRaw . ' 23:59:59';

$where = "WHERE 1=1";
$statusJoin = '';
$params = [];

if ($tech_id) {
    $where .= " AND o.technician_id = ?";
    $params[] = $tech_id;
}

switch ($type) {
    case 'received':
        $where .= " AND o.created_at BETWEEN ? AND ?";
        break;
    case 'in_progress':
        $statusJoin = "
            JOIN (
                SELECT order_id, MAX(changed_at) AS transition_at
                FROM order_status_log
                WHERE new_status IN ('Diagnostics','In Repair','In Progress','Waiting for Parts')
                GROUP BY order_id
            ) report_status ON report_status.order_id = o.id";
        $where .= " AND o.status IN ('Diagnostics','In Repair','In Progress','Waiting for Parts') AND report_status.transition_at BETWEEN ? AND ?";
        break;
    case 'completed':
        $statusJoin = "
            JOIN (
                SELECT order_id, MAX(changed_at) AS transition_at
                FROM order_status_log
                WHERE new_status IN ('Ready','Issued','Completed','Collected')
                GROUP BY order_id
            ) report_status ON report_status.order_id = o.id";
        $where .= " AND o.status IN ('Ready','Issued','Completed','Collected') AND report_status.transition_at BETWEEN ? AND ?";
        break;
    case 'cancelled':
        $statusJoin = "
            JOIN (
                SELECT order_id, MAX(changed_at) AS transition_at
                FROM order_status_log
                WHERE new_status IN ('Issued Without Repair','Repair Cancelled','Cancelled')
                GROUP BY order_id
            ) report_status ON report_status.order_id = o.id";
        $where .= " AND o.status IN ('Issued Without Repair','Repair Cancelled','Cancelled') AND report_status.transition_at BETWEEN ? AND ?";
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
        exit;
}

$params[] = $start;
$params[] = $end;

try {
    $sql = "SELECT o.id, o.device_brand, o.device_model, o.status, o.final_cost, o.estimated_cost, o.created_at, c.first_name, c.last_name 
            FROM orders o 
            JOIN customers c ON o.customer_id = c.id 
            $statusJoin
            $where 
            ORDER BY o.id DESC
            LIMIT 1001";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $truncated = count($orders) > 1000;
    if ($truncated) {
        array_pop($orders);
    }
    echo json_encode(['success' => true, 'data' => $orders, 'truncated' => $truncated]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
