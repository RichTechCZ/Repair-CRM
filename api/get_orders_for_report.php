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
}

$start = ($_GET['start_date'] ?? date('Y-m-01')) . ' 00:00:00';
$end = ($_GET['end_date'] ?? date('Y-m-t')) . ' 23:59:59';

$where = "WHERE 1=1";
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
        $where .= " AND o.status = 'In Progress' AND o.updated_at BETWEEN ? AND ?";
        break;
    case 'completed':
        $where .= " AND o.status IN ('Completed', 'Collected') AND o.updated_at BETWEEN ? AND ?";
        break;
    case 'cancelled':
        $where .= " AND o.status = 'Cancelled' AND o.updated_at BETWEEN ? AND ?";
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
            $where 
            ORDER BY o.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
