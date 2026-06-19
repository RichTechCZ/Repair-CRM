<?php
/**
 * API: Copy Order by ID
 * Returns order data as JSON for pre-filling the New Order form.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
header('Content-Type: application/json');

checkApiRateLimit('copy_order', 30, 60);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

$order_id = intval($_GET['id'] ?? 0);
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

if (!currentUserCanViewOrder($order_id)) {
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

try {
    $stmt = $pdo->prepare('
        SELECT o.id, o.customer_id, o.device_type, o.order_type, o.device_model, o.device_brand,
               o.serial_number, o.serial_number_2, o.appearance, o.pin_code, o.priority,
               o.problem_description, o.technician_notes, o.estimated_cost, o.technician_id,
               o.shipping_method, c.first_name, c.last_name, c.phone, c.email, c.company
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'message' => __('copy_order_not_found')]);
        exit;
    }

    echo json_encode(['success' => true, 'order' => $order]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
