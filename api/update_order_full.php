<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => ['action' => 'order_full', 'max' => 30, 'window' => 60],
]);

$order_id = $_POST['order_id'] ?? null;
if (!$order_id) {
    api_json_exit(['success' => false, 'message' => __('missing_id')]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new Exception('Order not found');
    }

    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    $is_admin = hasPermission('admin_access');
    $new_status = $_POST['status'] ?? $current['status'];
    $canonical_new_status = canonicalOrderStatus($new_status);
    if (!in_array($canonical_new_status, getAllStatuses(), true)) {
        throw new Exception('Invalid status');
    }
    $new_status = getOrderStatusStorageValue($canonical_new_status);

    $incoming_final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)($current['final_cost'] ?? 0);
    if ($incoming_final_cost < 0) {
        throw new Exception(__('required_for_issue'));
    }

    // Shipping is managed via the dedicated status/shipping forms; full edit only enforces cost.
    OrderStatusService::assertIssuedRequirements(
        $canonical_new_status,
        $incoming_final_cost,
        null,
        false
    );

    $canonical_current_status = canonicalOrderStatus($current['status']);
    OrderStatusService::assertCanChangeFromTerminal(
        $canonical_current_status,
        $canonical_new_status,
        $is_admin
    );

    $incoming_cancellation_reason = trim($_POST['cancellation_reason'] ?? '');
    OrderStatusService::assertCancellationReason(
        $canonical_new_status,
        $incoming_cancellation_reason,
        tableColumnExists('orders', 'cancellation_reason'),
        true,
        $current['cancellation_reason'] ?? null
    );

    $sql = "UPDATE orders SET
        customer_id = ?,
        device_model = ?,
        device_brand = ?,
        device_type = ?,
        order_type = ?,
        status = ?,
        technician_id = ?,
        estimated_cost = ?,
        final_cost = ?,
        extra_expenses = ?,
        problem_description = ?,
        technician_notes = ?,
        pin_code = ?,
        appearance = ?,
        priority = ?,
        serial_number = ?,
        serial_number_2 = ?,
        updated_at = CURRENT_TIMESTAMP";

    $new_tech_id = ($is_admin && isset($_POST['technician_id']) && $_POST['technician_id'] !== '')
        ? $_POST['technician_id']
        : $current['technician_id'];

    $params = [
        ($is_admin && !empty($_POST['customer_id'])) ? $_POST['customer_id'] : $current['customer_id'],
        isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'],
        isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand'],
        isset($_POST['device_type']) ? $_POST['device_type'] : $current['device_type'],
        isset($_POST['order_type']) ? $_POST['order_type'] : $current['order_type'],
        $new_status,
        $new_tech_id,
        isset($_POST['estimated_cost']) ? $_POST['estimated_cost'] : $current['estimated_cost'],
        isset($_POST['final_cost']) ? $_POST['final_cost'] : $current['final_cost'],
        ($is_admin && isset($_POST['extra_expenses'])) ? $_POST['extra_expenses'] : $current['extra_expenses'],
        isset($_POST['problem_description']) ? $_POST['problem_description'] : $current['problem_description'],
        isset($_POST['technician_notes']) ? $_POST['technician_notes'] : $current['technician_notes'],
        isset($_POST['pin_code']) ? $_POST['pin_code'] : $current['pin_code'],
        isset($_POST['appearance']) ? $_POST['appearance'] : $current['appearance'],
        isset($_POST['priority']) ? $_POST['priority'] : $current['priority'],
        isset($_POST['serial_number']) ? $_POST['serial_number'] : $current['serial_number'],
        isset($_POST['serial_number_2']) ? $_POST['serial_number_2'] : $current['serial_number_2'],
    ];

    if (tableColumnExists('orders', 'cancellation_reason')) {
        if (in_array($canonical_new_status, OrderStatusService::REASON_REQUIRED, true) && $incoming_cancellation_reason !== '') {
            $sql .= ', cancellation_reason = ?';
            $params[] = $incoming_cancellation_reason;
        } elseif (!in_array($canonical_new_status, OrderStatusService::REASON_REQUIRED, true)) {
            $sql .= ', cancellation_reason = ?';
            $params[] = null;
        }
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    $effects = OrderStatusService::applyInTransaction(
        $pdo,
        (int)$order_id,
        $current['status'],
        $new_status,
        $_POST['final_cost'] ?? $final_cost
    );

    saveDeviceModelUsage($_POST['device_brand'] ?? $current['device_brand'], $_POST['device_model'] ?? $current['device_model']);

    $pdo->commit();

    $sync_result = OrderStatusService::afterCommit(
        $pdo,
        (int)$order_id,
        $current['status'],
        $new_status,
        $final_cost,
        $effects['invoice_to_sync']
    );

    // Full edit can reassign technician — notify like status change.
    OrderStatusService::notifyTechnicianReassignment(
        $pdo,
        (int)$order_id,
        $current['technician_id'],
        $new_tech_id,
        $current
    );

    api_json_exit(['success' => true, 'myinvoice_sync' => $sync_result]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
