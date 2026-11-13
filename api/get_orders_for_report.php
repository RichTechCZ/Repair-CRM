<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => false,
    'csrf' => false,
    'rate' => 'get_orders_for_report',
]);

$type = $_GET['type'] ?? '';

// Security: Admin/admin_access may filter by tech_id; technicians are strictly scoped to their own non-empty tech_id.
if (hasPermission('admin_access')) {
    $requestedTechId = filter_input(INPUT_GET, 'tech_id', FILTER_VALIDATE_INT);
    $tech_id = ($requestedTechId && $requestedTechId > 0) ? $requestedTechId : null;
} elseif (($_SESSION['role'] ?? '') === 'technician' && !empty($_SESSION['tech_id'])) {
    $tech_id = (int)$_SESSION['tech_id'];
} else {
    api_json_exit(['success' => false, 'message' => __('access_denied_msg')], 403);
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
    api_json_exit(['success' => false, 'message' => 'Invalid report date range'], 400);
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
        api_json_exit(['success' => false, 'message' => 'Invalid type'], 400);
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
    api_json_exit(['success' => true, 'data' => $orders, 'truncated' => $truncated]);
} catch (Exception $e) {
    api_exception_exit($e, 500);
}
