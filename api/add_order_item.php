<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'add_order_item',
]);
$order_id = $_POST['order_id'] ?? null;
$inventory_id = $_POST['inventory_id'] ?? null;
$qty = filter_var(
    $_POST['quantity'] ?? 1,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 100000]]
);
$mode = $_POST['mode'] ?? 'inventory';
$manual_part_name = trim($_POST['part_name'] ?? '');
$manual_source = trim($_POST['source'] ?? '');
$manual_price = $_POST['price'] ?? null;

if (
    !$order_id ||
    !in_array($mode, ['inventory', 'manual'], true) ||
    ($mode === 'inventory' && !$inventory_id)
) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

if ($qty === false) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

if ($mode === 'manual' && ($manual_part_name === '' || $manual_source === '' || $manual_price === null || $manual_price === '')) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}
if (
    $mode === 'manual' &&
    (!is_numeric($manual_price) || !is_finite((float)$manual_price) || (float)$manual_price < 0)
) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Check permissions
    $stmt = $pdo->prepare("SELECT technician_id, status FROM orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception("Order not found");
    }

    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    // Parts are only physically consumed while the order is in a repaired/handed-over
    // state. In every other status the stock is adjusted later by the status change.
    $order_is_consuming = in_array(canonicalOrderStatus($order['status']), ['Ready', 'Issued'], true);

    if ($mode === 'manual') {
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, part_name, source, quantity, price) VALUES (?, NULL, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $manual_part_name, $manual_source, $qty, (float)$manual_price]);
    } else {
        // Get current price from inventory and verify stock availability
        $stmt = $pdo->prepare("SELECT sale_price, part_name, quantity FROM inventory WHERE id = ? FOR UPDATE");
        $stmt->execute([$inventory_id]);
        $inventory_item = $stmt->fetch();
        if (!$inventory_item) {
            throw new Exception(__('not_found'));
        }

        // When the order already consumes stock, adding more must not drive stock negative.
        $stock_to_check = $order_is_consuming ? $qty : 0;
        if ($order_is_consuming && ($inventory_item['quantity'] - $stock_to_check) < 0) {
            throw new Exception(__('insufficient_stock'));
        }

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $inventory_id, $qty, $inventory_item['sale_price']]);

        if ($order_is_consuming) {
            changeInventoryQuantity($inventory_id, -$qty);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
