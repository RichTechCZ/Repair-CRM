<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean(); // discard any output/warnings
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$id = $_POST['id'] ?? null;
$new_qty = (float)($_POST['quantity'] ?? 1);
$new_price = (float)($_POST['price'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch current state
    $stmt = $pdo->prepare("SELECT oi.*, o.status, o.technician_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new Exception("Item not found");
    }

    // Check permissions
    if (!hasPermission('edit_orders') && ($item['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0)) {
        throw new Exception(__('access_denied_msg'));
    }

    // If order is completed/collected, adjust inventory
    if (in_array($item['status'], ['Completed', 'Collected'])) {
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
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
