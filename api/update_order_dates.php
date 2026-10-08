<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_order_dates',
]);

$order_id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$created_at = OrderStatusService::parseManualStatusDate($_POST['created_at'] ?? '');
$updated_at = OrderStatusService::parseManualStatusDate($_POST['updated_at'] ?? '');

if (!$order_id || !$created_at || !$updated_at) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

if (!currentUserCanEditOrder($order_id)) {
    http_response_code(403);
    api_json_exit(['success' => false, 'message' => __('access_denied_msg')]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, status, shipping_date FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found');
    }

    $sql = 'UPDATE orders SET created_at = ?, updated_at = ?';
    $params = [$created_at, $updated_at];
    $canonical = canonicalOrderStatus((string)$order['status']);
    if (in_array($canonical, ['Issued', 'Collected'], true)) {
        $sql .= ', shipping_date = ?';
        $params[] = $updated_at;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $order_id;
    $pdo->prepare($sql)->execute($params);

    OrderStatusService::syncStatusHistoryDate(
        $pdo,
        (int)$order_id,
        (string)$order['status'],
        $updated_at
    );

    $pdo->commit();
    api_json_exit(['success' => true, 'message' => 'Dates updated']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
