<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_order_dates',
]);
$order_id = $_POST['order_id'] ?? null;
$created_at = $_POST['created_at'] ?? null;
$updated_at = $_POST['updated_at'] ?? null;

if (!$order_id || !$created_at || !$updated_at) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

if (!currentUserCanEditOrder($order_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE orders SET created_at = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$created_at, $updated_at, $order_id]);

    echo json_encode(['success' => true, 'message' => 'Dates updated']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
