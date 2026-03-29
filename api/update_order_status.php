<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
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
$technician_id = $_REQUEST['technician_id'] ?? null;

if (!$order_id || !$new_status) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT status, technician_id, estimated_cost, final_cost FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $order_data = $stmt->fetch();

    if (!$order_data) {
        throw new Exception('Order not found');
    }

    if (!hasPermission('edit_orders') && ($order_data['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0)) {
        throw new Exception(__('access_denied_msg'));
    }

    $current_status = $order_data['status'];
    $current_tech_id = $order_data['technician_id'];
    $current_estimated = $order_data['estimated_cost'];
    $current_final = $order_data['final_cost'];

    if ($current_status === 'Collected' && $new_status !== 'Collected') {
        throw new Exception(__('status_change_after_collected_forbidden'));
    }

    $finishing_statuses = ['Completed', 'Collected'];
    $was_finished = in_array($current_status, $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);

    $sql = 'UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP';
    $params = [$new_status];

    if ($new_status === 'Collected') {
        $sql .= ', shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP)';
    }

    if ($new_status === 'Collected' && ($final_cost === null || $final_cost === '')) {
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

    $sql .= ' WHERE id = ?';
    $params[] = $order_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($current_status !== $new_status) {
        logOrderStatusChange($order_id, $current_status, $new_status);
    }

    if (!$was_finished && $is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
    } elseif ($was_finished && !$is_finishing) {
        processOrderInventoryChange($order_id, $is_finishing, $was_finished);
    }

    $pdo->commit();

    $notify_id = $technician_id ? $technician_id : $current_tech_id;
    if ($notify_id) {
        $tech = $pdo->prepare('SELECT telegram_id, name FROM technicians WHERE id = ?');
        $tech->execute([$notify_id]);
        $techData = $tech->fetch();

        if ($techData && $techData['telegram_id']) {
            $msg = sprintf(__('tg_order_update_title'), $order_id) . "\n";
            $msg .= sprintf(__('tg_new_status'), $new_status) . "\n";
            if ($final_cost !== null) {
                $msg .= sprintf(__('tg_cost'), formatMoney($final_cost)) . "\n";
            }
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $link = $protocol . $_SERVER['HTTP_HOST'] . '/view_order.php?id=' . $order_id;
            $msg .= sprintf(__('tg_open_crm'), $link);
            sendTelegramNotification($techData['telegram_id'], $msg);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Status updated']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
