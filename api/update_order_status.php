<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => ['action' => 'order_status', 'max' => 30, 'window' => 60],
]);

$order_id = $_POST['order_id'] ?? $_REQUEST['order_id'] ?? null;
$new_status = $_POST['status'] ?? $_REQUEST['status'] ?? null;
$final_cost = $_POST['final_cost'] ?? $_REQUEST['final_cost'] ?? null;
$shipping_method = trim((string)($_POST['shipping_method'] ?? $_REQUEST['shipping_method'] ?? ''));
if (
    $final_cost !== null &&
    $final_cost !== '' &&
    (!is_numeric($final_cost) || !is_finite((float)$final_cost) || (float)$final_cost < 0)
) {
    $postedOrderType = trim((string)($_POST['order_type'] ?? $_REQUEST['order_type'] ?? ''));
    $issuedNeedsFinalCost = canonicalOrderStatus((string)$new_status) === 'Issued'
        && OrderStatusService::issuedRequiresFinalCost($postedOrderType, $shipping_method);
    api_json_exit([
        'success' => false,
        'message' => $issuedNeedsFinalCost ? __('required_final_cost_for_issue') : __('missing_data'),
    ]);
}

$is_admin = hasPermission('admin_access');
$technician_id = $is_admin ? ($_POST['technician_id'] ?? $_REQUEST['technician_id'] ?? null) : null;
$cancellation_reason = $_POST['cancellation_reason'] ?? $_REQUEST['cancellation_reason'] ?? null;
$extra_expenses = $_POST['extra_expenses'] ?? $_REQUEST['extra_expenses'] ?? null;
if (
    $extra_expenses !== null &&
    $extra_expenses !== '' &&
    (!is_numeric($extra_expenses) || !is_finite((float)$extra_expenses) || (float)$extra_expenses < 0)
) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

$can_store_cancellation_reason = tableColumnExists('orders', 'cancellation_reason');

if (!$order_id || !$new_status) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

$requested_status = $new_status;
$canonical_new_status = canonicalOrderStatus($requested_status);

if (!in_array($canonical_new_status, getAllStatuses(), true)) {
    api_json_exit(['success' => false, 'message' => 'Invalid status']);
}

$new_status = getOrderStatusStorageValue($requested_status);

try {
    OrderStatusService::assertCancellationReason(
        $canonical_new_status,
        $cancellation_reason,
        $can_store_cancellation_reason
    );

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT status, technician_id, estimated_cost, final_cost, shipping_method, order_type, device_brand, device_model, problem_description
         FROM orders WHERE id = ? FOR UPDATE'
    );
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();

    if (!$order_data) {
        throw new Exception('Order not found');
    }

    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    $current_status = $order_data['status'];
    $canonical_current_status = canonicalOrderStatus($current_status);
    $current_tech_id = $order_data['technician_id'];
    $current_estimated = $order_data['estimated_cost'];
    $current_final = $order_data['final_cost'];

    OrderStatusService::assertCanChangeFromTerminal(
        $canonical_current_status,
        $canonical_new_status,
        $is_admin
    );

    $effective_final = ($final_cost !== null && $final_cost !== '') ? (float)$final_cost : $current_final;
    if ($effective_final === null || $effective_final === '' || (float)$effective_final <= 0) {
        if ($current_estimated !== null && $current_estimated !== '' && (float)$current_estimated > 0) {
            $effective_final = (float)$current_estimated;
        }
    }
    $effective_shipping = $shipping_method !== '' ? $shipping_method : ($order_data['shipping_method'] ?? null);
    OrderStatusService::assertIssuedRequirements(
        $canonical_new_status,
        $effective_final,
        $effective_shipping,
        true,
        $order_data['order_type'] ?? null
    );

    $sql = 'UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$new_status];

    if ($canonical_new_status === 'Issued') {
        $sql .= ', shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP)';
        if ($shipping_method !== '') {
            $sql .= ', shipping_method = ?';
            $params[] = $shipping_method;
        }
    }

    if ($canonical_new_status === 'Issued' && ($final_cost === null || $final_cost === '')) {
        $final_cost = ($current_final !== null && $current_final !== '') ? $current_final : $current_estimated;
    }

    if ($final_cost !== null && $final_cost !== '') {
        $sql .= ', final_cost = ?';
        $params[] = $final_cost;
    }

    $sql .= ', technician_id = ?';
    $params[] = ($technician_id && $technician_id !== '') ? $technician_id : $current_tech_id;

    if ($extra_expenses !== null && $extra_expenses !== '' && $is_admin) {
        $sql .= ', extra_expenses = ?';
        $params[] = (float)$extra_expenses;
    }

    if ($can_store_cancellation_reason && in_array($canonical_new_status, OrderStatusService::REASON_REQUIRED, true)) {
        $sql .= ', cancellation_reason = ?';
        $params[] = trim((string)$cancellation_reason);
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $effects = OrderStatusService::applyInTransaction(
        $pdo,
        (int)$order_id,
        $current_status,
        $new_status,
        $final_cost
    );

    $pdo->commit();

    $sync_result = OrderStatusService::afterCommit(
        $pdo,
        (int)$order_id,
        $current_status,
        $new_status,
        $final_cost,
        $effects['invoice_to_sync']
    );

    $effective_tech_id = ($technician_id !== null && $technician_id !== '')
        ? (int)$technician_id
        : (int)($current_tech_id ?? 0);
    OrderStatusService::notifyTechnicianReassignment(
        $pdo,
        (int)$order_id,
        $current_tech_id,
        $effective_tech_id,
        $order_data
    );

    api_json_exit(['success' => true, 'message' => 'Status updated', 'myinvoice_sync' => $sync_result]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
