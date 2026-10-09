<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'delete_order_item',
]);
$id = $_POST['id'] ?? null; // ID of order_items record

if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch the item and order status
    $stmt = $pdo->prepare("SELECT oi.*, o.status, o.technician_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new Exception("Item not found");
    }

    // Check permissions
    if (!currentUserCanEditOrder($item['order_id'])) {
        throw new Exception(__('access_denied_msg'));
    }
    OrderStatusService::assertClosedOrderEditable(canonicalOrderStatus((string)$item['status']), hasPermission('admin_access'));

    // If order is in a stock-consuming state (repaired/handed over), return the parts
    if (in_array(canonicalOrderStatus($item['status']), ['Ready', 'Issued'], true) && !empty($item['inventory_id'])) {
        changeInventoryQuantity($item['inventory_id'], $item['quantity']);
    }

    // Delete the item
    $del = $pdo->prepare("DELETE FROM order_items WHERE id = ?");
    $del->execute([$id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
