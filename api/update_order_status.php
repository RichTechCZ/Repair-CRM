<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
require_once '../models/InvoiceAutomation.php';
ob_clean();
header('Content-Type: application/json');

checkApiRateLimit('order_status', 30, 60);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$order_id = $_REQUEST['order_id'] ?? null;
$new_status = $_REQUEST['status'] ?? null;
$final_cost = $_REQUEST['final_cost'] ?? null;
$technician_id = hasPermission('admin_access') ? ($_REQUEST['technician_id'] ?? null) : null;
$cancellation_reason = $_REQUEST['cancellation_reason'] ?? null;
$shipping_method = trim($_REQUEST['shipping_method'] ?? '');

$allowed_statuses = getAllStatuses();

// Terminal statuses — once reached, no further changes are allowed
$terminal_statuses = ['Issued', 'Issued Without Repair', 'Repair Cancelled'];

// Statuses that require a cancellation_reason
$reason_required_statuses = ['Issued Without Repair', 'Repair Cancelled'];

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

if (!in_array($new_status, $allowed_statuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

// Validate cancellation_reason for terminal rejection statuses
if (in_array($new_status, $reason_required_statuses, true) && empty(trim($cancellation_reason ?? ''))) {
    echo json_encode(['success' => false, 'message' => __('cancellation_reason')]);
    exit;
}

try {
    $pdo->beginTransaction();
    $invoice_to_sync = null;

    $stmt = $pdo->prepare('SELECT status, technician_id, estimated_cost, final_cost, shipping_method FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();

    if (!$order_data) {
        throw new Exception('Order not found');
    }

    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    $current_status = $order_data['status'];
    $current_tech_id = $order_data['technician_id'];
    $current_estimated = $order_data['estimated_cost'];
    $current_final = $order_data['final_cost'];

    // Block changes from terminal statuses
    if (in_array($current_status, $terminal_statuses, true) && $new_status !== $current_status) {
        throw new Exception(__('status_change_after_collected_forbidden'));
    }

    // Validate 'Issued' requires final_cost and shipping_method
    if ($new_status === 'Issued') {
        $effective_final = ($final_cost !== null && $final_cost !== '') ? $final_cost : $current_final;
        $effective_shipping = $shipping_method !== '' ? $shipping_method : ($order_data['shipping_method'] ?? null);
        if (empty($effective_final) || $effective_final <= 0) {
            throw new Exception(__('required_for_issue'));
        }
        if (empty($effective_shipping)) {
            throw new Exception(__('required_for_issue'));
        }
    }

    $finishing_statuses = ['Ready', 'Issued', 'Issued Without Repair'];
    $was_finished = in_array($current_status, $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);

    $sql = 'UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$new_status];

    // Auto-set shipping_date when Issued
    if ($new_status === 'Issued') {
        $sql .= ', shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP)';
        if ($shipping_method !== '') {
            $sql .= ', shipping_method = ?';
            $params[] = $shipping_method;
        }
    }

    // Fallback final_cost for Issued
    if ($new_status === 'Issued' && ($final_cost === null || $final_cost === '')) {
        $final_cost = ($current_final !== null && $current_final !== '') ? $current_final : $current_estimated;
    }

    if ($final_cost !== null && $final_cost !== '') {
        $sql .= ', final_cost = ?';
        $params[] = $final_cost;
    }

    $sql .= ', technician_id = ?';
    $params[] = ($technician_id && $technician_id !== '') ? $technician_id : $current_tech_id;

    if (isset($_REQUEST['extra_expenses']) && ($_SESSION['role'] ?? '') === 'admin') {
        $sql .= ', extra_expenses = ?';
        $params[] = $_REQUEST['extra_expenses'];
    }

    // Save cancellation_reason for terminal rejection statuses
    if (in_array($new_status, $reason_required_statuses, true)) {
        $sql .= ', cancellation_reason = ?';
        $params[] = trim($cancellation_reason);
    }

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($current_status !== $new_status) {
        logOrderStatusChange($order_id, $current_status, $new_status);
    }

    if (!$was_finished && $is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
        if ($new_status === 'Ready' && get_setting('acc_auto_create_invoice', '0') == '1') {
            $invoiceResult = createLocalInvoiceForCompletedOrder($pdo, (int)$order_id, $final_cost);
            if ($invoiceResult['success'] ?? false) {
                $invoice_to_sync = (int)$invoiceResult['id'];
            } else {
                error_log('Auto invoice creation failed for order #' . $order_id . ': ' . ($invoiceResult['error'] ?? 'unknown error'));
            }
        }
    } elseif ($was_finished && !$is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
    }

    $pdo->commit();

    $sync_result = null;
    if ($invoice_to_sync) {
        $sync_result = syncInvoiceToMyInvoice($pdo, $invoice_to_sync);
    }

    $notify_id = $technician_id ? $technician_id : $current_tech_id;
    if ($notify_id) {
        $tech = $pdo->prepare('SELECT telegram_id, name FROM technicians WHERE id = ?');
        $tech->execute([$notify_id]);
        $techData = $tech->fetch();

        if ($techData && $techData['telegram_id']) {
            $msg = sprintf(__('tg_order_update_title'), $order_id) . "\n";
            $msg .= sprintf(__('tg_new_status'), getStatusLabel($new_status)) . "\n";
            if ($final_cost !== null) {
                $msg .= sprintf(__('tg_cost'), formatMoney($final_cost)) . "\n";
            }
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $link = $protocol . $_SERVER['HTTP_HOST'] . '/view_order.php?id=' . $order_id;
            $msg .= sprintf(__('tg_open_crm'), $link);
            sendTelegramNotification($techData['telegram_id'], $msg);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Status updated', 'myinvoice_sync' => $sync_result]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
