<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_shipping',
]);
$order_id = $_POST['order_id'] ?? null;
$shipping_method = $_POST['shipping_method'] ?? '';
$shipping_tracking = $_POST['shipping_tracking'] ?? '';
$shipping_date = $_POST['shipping_date'] ?? null;

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Order ID']);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT technician_id FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    if (!currentUserCanEditOrder($order_id)) {
        echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
        exit;
    }

    $sql = 'UPDATE orders SET shipping_method = ?, shipping_tracking = ?, shipping_date = ? WHERE id = ?';
    $params = [
        $shipping_method,
        $shipping_tracking,
        $shipping_date ?: null,
        $order_id
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
