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
    if (!in_array($new_status, $allowed_statuses, true)) {
        throw new Exception('Invalid status');
    }

    $incoming_final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)($current['final_cost'] ?? 0);
    if ($new_status === 'Issued' && ($incoming_final_cost <= 0 || empty($current['shipping_method']))) {
        throw new Exception(__('required_for_issue'));
    }

    if (in_array($new_status, ['Issued Without Repair', 'Repair Cancelled'], true) && empty(trim($current['cancellation_reason'] ?? ''))) {
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
        updated_at = CURRENT_TIMESTAMP
        WHERE id = ?";

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
        $order_id
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $technician_id = ($is_admin && isset($_POST['technician_id'])) ? $_POST['technician_id'] : $current['technician_id'];
    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    $finishing_statuses = ['Ready', 'Issued', 'Issued Without Repair'];
    $was_finished = in_array($current['status'], $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);

    if (in_array($current['status'], ['Issued', 'Issued Without Repair', 'Repair Cancelled'], true) && $new_status !== $current['status']) {
        throw new Exception(__('status_change_after_collected_forbidden'));
    }

    if ($current['status'] !== $new_status) {
        if (!$was_finished && $is_finishing) {
            processOrderInventoryChange($order_id, $is_finishing, $was_finished);

            $tech = $pdo->prepare('SELECT telegram_id, name FROM technicians WHERE id = ?');
            $tech->execute([$technician_id ?: $current['technician_id']]);
            $techData = $tech->fetch();
            if ($techData && $techData['telegram_id']) {
                $msg = sprintf(__('tg_order_ready'), $order_id) . "\n";
                $msg .= sprintf(__('tg_device'), ($_POST['device_model'] ?? $current['device_model'])) . "\n";
                $msg .= sprintf(__('tg_final_price'), formatMoney($final_cost)) . "\n";
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $link = $protocol . $_SERVER['HTTP_HOST'] . '/view_order.php?id=' . $order_id;
                $msg .= sprintf(__('tg_open_link'), $link);
                sendTelegramNotification($techData['telegram_id'], $msg);
            }

            if ($new_status === 'Ready' && get_setting('acc_auto_create_invoice', '0') == '1') {
                $invoiceResult = createLocalInvoiceForCompletedOrder($pdo, (int)$order_id, $_POST['final_cost'] ?? null);
                if ($invoiceResult['success'] ?? false) {
                    $invoice_to_sync = (int)$invoiceResult['id'];
                } else {
                    error_log('Auto invoice creation failed for order #' . $order_id . ': ' . ($invoiceResult['error'] ?? 'unknown error'));
                }
            }
        } elseif ($was_finished && !$is_finishing) {
            processOrderInventoryChange($order_id, $is_finishing, $was_finished);
        }
        logOrderStatusChange($order_id, $current['status'], $new_status);
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
