<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'delete_inventory',
]);
$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    // Check if item is used in orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE inventory_id = ?");
    $stmt->execute([$id]);
    $usage = $stmt->fetchColumn();

    if ($usage > 0) {
        // If used in orders, we can't delete
        echo json_encode(['success' => false, 'message' => __('item_hidden_in_orders')]);
    } else {
        // If not used, delete permanently
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
