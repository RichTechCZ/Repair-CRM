<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_shipping',
]);

$order_id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$shipping_method = trim((string)($_POST['shipping_method'] ?? ''));
$shipping_tracking = trim((string)($_POST['shipping_tracking'] ?? ''));
$raw_shipping_date = trim((string)($_POST['shipping_date'] ?? ''));

if (!$order_id) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}
if (mb_strlen($shipping_method) > 50 || mb_strlen($shipping_tracking) > 100) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

// Accept a date (Y-m-d) or a datetime-local value; anything else is rejected, not silently stored.
$shipping_date = null;
if ($raw_shipping_date !== '') {
    $shipping_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw_shipping_date)
        ? OrderStatusService::parseManualStatusDate($raw_shipping_date . ' 00:00')
        : OrderStatusService::parseManualStatusDate($raw_shipping_date);
    if ($shipping_date === null) {
        api_json_exit(['success' => false, 'message' => __('missing_data')]);
    }
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status, shipping_method, shipping_date FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception(__('not_found'));
    }
    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    $canonical = canonicalOrderStatus((string)$order['status']);

    if ($canonical === 'Issued' && $shipping_date === null) {
        $shipping_date = $order['shipping_date'];
    }

    // Closed orders: the tracking number is still added after handover to a carrier,
    // but the handover method and the finance-period date are admin-only.
    $methodChanged = $shipping_method !== (string)($order['shipping_method'] ?? '');
    $dateChanged = substr((string)($shipping_date ?? ''), 0, 16) !== substr((string)($order['shipping_date'] ?? ''), 0, 16);
    if ($methodChanged || $dateChanged) {
        OrderStatusService::assertClosedOrderEditable($canonical, hasPermission('admin_access'));
    }

    if ($canonical === 'Issued') {
        // An issued order must keep a handover method and the shipping_date that places it
        // in a finance period; clearing either would silently drop it from revenue and payroll.
        if (!OrderStatusService::issuedShippingSatisfied($shipping_method !== '' ? $shipping_method : null)) {
            throw new Exception(__('required_for_issue'));
        }
    }

    $pdo->prepare('UPDATE orders SET shipping_method = ?, shipping_tracking = ?, shipping_date = ? WHERE id = ?')
        ->execute([
            $shipping_method !== '' ? $shipping_method : null,
            $shipping_tracking !== '' ? $shipping_tracking : null,
            $shipping_date,
            $order_id,
        ]);

    $pdo->commit();
    api_json_exit(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
