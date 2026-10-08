<?php
/**
 * API: Copy Order by ID
 * Returns order data as JSON for pre-filling the New Order form.
 * Device PIN is intentionally omitted: copy should not leak decrypted PIN ciphertext/plaintext.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'auth' => true,
    'post' => false,
    'csrf' => false,
    'rate' => ['action' => 'copy_order', 'max' => 30, 'window' => 60],
    'json' => true,
]);

$order_id = intval($_GET['id'] ?? 0);
if (!$order_id) {
    api_json_exit(['success' => false, 'message' => __('missing_id')], 400);
}

if (!currentUserCanViewOrder($order_id)) {
    api_json_exit(['success' => false, 'message' => __('access_denied_msg')], 403);
}

try {
    $stmt = $pdo->prepare('
        SELECT o.id, o.customer_id, o.device_type, o.order_type, o.device_model, o.device_brand,
               o.serial_number, o.serial_number_2, o.appearance, o.priority,
               o.problem_description, o.technician_notes, o.estimated_cost, o.technician_id,
               o.shipping_method, c.first_name, c.last_name, c.phone, c.email, c.company
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ?
    ');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        api_json_exit(['success' => false, 'message' => __('copy_order_not_found')], 404);
    }

    // Explicitly never include device PIN in copy payloads.
    unset($order['pin_code']);

    api_json_exit(['success' => true, 'order' => $order]);
} catch (Exception $e) {
    api_exception_exit($e, 500);
}
