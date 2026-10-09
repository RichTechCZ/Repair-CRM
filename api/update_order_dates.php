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

    $stmt = $pdo->prepare('SELECT id, status, shipping_date, updated_at FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found');
    }
    $canonical = canonicalOrderStatus((string)$order['status']);
    OrderStatusService::assertClosedOrderEditable($canonical, hasPermission('admin_access'));

    // The form always posts the status date. Only rewrite status history (and the
    // Issued shipping_date that drives finance periods) when the operator actually
    // changed it; editing only created_at must not move the order between periods.
    $currentStatusDate = (string)$order['updated_at'];
    $logStmt = $pdo->prepare('SELECT new_status, changed_at FROM order_status_log WHERE order_id = ? ORDER BY changed_at DESC, id DESC');
    $logStmt->execute([$order_id]);
    foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $logRow) {
        if (canonicalOrderStatus((string)$logRow['new_status']) === $canonical) {
            $currentStatusDate = (string)$logRow['changed_at'];
            break;
        }
    }
    // datetime-local inputs carry minute precision.
    $statusDateChanged = substr($currentStatusDate, 0, 16) !== substr($updated_at, 0, 16)
        // Legacy Issued orders without shipping_date are invisible to finance; re-saving repairs them.
        || (in_array($canonical, ['Issued', 'Collected'], true) && empty($order['shipping_date']));

    $sql = 'UPDATE orders SET created_at = ?, updated_at = ?';
    $params = [$created_at, $updated_at];
    if ($statusDateChanged && in_array($canonical, ['Issued', 'Collected'], true)) {
        $sql .= ', shipping_date = ?';
        $params[] = $updated_at;
    }
    $sql .= ' WHERE id = ?';
    $params[] = $order_id;
    $pdo->prepare($sql)->execute($params);

    if ($statusDateChanged) {
        OrderStatusService::syncStatusHistoryDate(
            $pdo,
            (int)$order_id,
            (string)$order['status'],
            $updated_at
        );
    }

    $pdo->commit();
    api_json_exit(['success' => true, 'message' => 'Dates updated']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
