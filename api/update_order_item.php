<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_order_item',
]);
$id = $_POST['id'] ?? null;
$new_qty = filter_var(
    $_POST['quantity'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 100000]]
);
$new_price_raw = $_POST['price'] ?? null;

if (
    !$id ||
    $new_qty === false ||
    !is_numeric($new_price_raw) ||
    !is_finite((float)$new_price_raw) ||
    (float)$new_price_raw < 0
) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}
$new_price = (float)$new_price_raw;

try {
    $pdo->beginTransaction();

    // Fetch current state
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

    // If order is in a stock-consuming state (repaired/handed over), adjust inventory
    if (in_array(canonicalOrderStatus($item['status']), ['Ready', 'Issued'], true) && !empty($item['inventory_id'])) {
        $diff = $new_qty - $item['quantity'];
        // Subtract the difference from stock
        changeInventoryQuantity($item['inventory_id'], -$diff);
    }

    // Update item
    $upd_item = $pdo->prepare("UPDATE order_items SET quantity = ?, price = ? WHERE id = ?");
    $upd_item->execute([$new_qty, $new_price, $id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
