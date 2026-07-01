<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../models/InvoiceAutomation.php';
ob_clean();
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

$order_id = $_POST['order_id'] ?? null;
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $pdo->beginTransaction();
    $invoice_to_sync = null;

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new Exception('Order not found');
    }

    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception('No permission');
    }

    $is_admin = hasPermission('admin_access');
    $allowed_statuses = getAllStatuses();
    $new_status = $_POST['status'] ?? $current['status'];
    $canonical_new_status = canonicalOrderStatus($new_status);
    if (!in_array($canonical_new_status, $allowed_statuses, true)) {
        throw new Exception('Invalid status');
    }
    $new_status = getOrderStatusStorageValue($canonical_new_status);

    $incoming_final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)($current['final_cost'] ?? 0);
    if ($incoming_final_cost < 0) {
        throw new Exception(__('required_for_issue'));
    }
    // Shipping method is managed via the status form / shipping form, so the full
    // edit only enforces a positive final cost for the Issued status.
    if ($canonical_new_status === 'Issued' && $incoming_final_cost <= 0) {
        throw new Exception(__('required_for_issue'));
    }

    $terminal_statuses = ['Issued', 'Issued Without Repair', 'Repair Cancelled'];
    $canonical_current_status = canonicalOrderStatus($current['status']);
    if (!$is_admin && in_array($canonical_current_status, $terminal_statuses, true) && $canonical_new_status !== $canonical_current_status) {
        throw new Exception(__('status_change_after_collected_forbidden'));
    }

    $incoming_cancellation_reason = trim($_POST['cancellation_reason'] ?? '');
    if (tableColumnExists('orders', 'cancellation_reason') && in_array($canonical_new_status, ['Issued Without Repair', 'Repair Cancelled'], true) && $incoming_cancellation_reason === '' && empty(trim($current['cancellation_reason'] ?? ''))) {
        throw new Exception(__('cancellation_reason'));
    }

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

    $params = [
        ($is_admin && !empty($_POST['customer_id'])) ? $_POST['customer_id'] : $current['customer_id'],
        isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'],
        isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand'],
        isset($_POST['device_type']) ? $_POST['device_type'] : $current['device_type'],
        isset($_POST['order_type']) ? $_POST['order_type'] : $current['order_type'],
        $new_status,
        ($is_admin && isset($_POST['technician_id']) && $_POST['technician_id'] !== '') ? $_POST['technician_id'] : $current['technician_id'],
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

    // Persist the cancellation reason for terminal rejection statuses when the
    // column exists and a reason was supplied (or clear it when leaving such a status).
    if (tableColumnExists('orders', 'cancellation_reason')) {
        if (in_array($canonical_new_status, ['Issued Without Repair', 'Repair Cancelled'], true) && $incoming_cancellation_reason !== '') {
            $sql .= ', cancellation_reason = ?';
            $params[] = $incoming_cancellation_reason;
        } elseif (!in_array($canonical_new_status, ['Issued Without Repair', 'Repair Cancelled'], true)) {
            $sql .= ', cancellation_reason = ?';
            $params[] = null;
        }
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $technician_id = ($is_admin && isset($_POST['technician_id'])) ? $_POST['technician_id'] : $current['technician_id'];
    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    // Inventory is only consumed for actually-repaired/handed-over statuses.
    $inventory_consuming_statuses = ['Ready', 'Issued'];
    $was_consuming = in_array($canonical_current_status, $inventory_consuming_statuses, true);
    $is_consuming = in_array($canonical_new_status, $inventory_consuming_statuses, true);

    if ($current['status'] !== $new_status) {
        if (!$was_consuming && $is_consuming) {
            processOrderInventoryChange($order_id, $is_consuming, $was_consuming);

            if ($canonical_new_status === 'Ready' && get_setting('acc_auto_create_invoice', '0') == '1') {
                $invoiceResult = createLocalInvoiceForCompletedOrder($pdo, (int)$order_id, $_POST['final_cost'] ?? null);
                if ($invoiceResult['success'] ?? false) {
                    $invoice_to_sync = (int)$invoiceResult['id'];
                } else {
                    error_log('Auto invoice creation failed for order #' . $order_id . ': ' . ($invoiceResult['error'] ?? 'unknown error'));
                }
            }
        } elseif ($was_consuming && !$is_consuming) {
            processOrderInventoryChange($order_id, $is_consuming, $was_consuming);
            cancelAutoInvoicesForOrder($pdo, (int)$order_id);
        }
        logOrderStatusChange($order_id, $current['status'], $new_status);
        sendOrderStatusAdminNotification($order_id, $canonical_new_status, $final_cost);
    }

    saveDeviceModelUsage($_POST['device_brand'] ?? $current['device_brand'], $_POST['device_model'] ?? $current['device_model']);

    $pdo->commit();

    if (ob_get_length()) {
        ob_clean();
    }
    $sync_result = null;
    if ($invoice_to_sync) {
        $sync_result = syncInvoiceToMyInvoice($pdo, $invoice_to_sync);
    }

    echo json_encode(['success' => true, 'myinvoice_sync' => $sync_result]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
