<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
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

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $current = $stmt->fetch();

    if (!$current) {
        throw new Exception('Order not found');
    }

    if (($_SESSION['role'] ?? '') === 'technician'
        && !hasPermission('edit_orders')
        && ($current['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0)
    ) {
        throw new Exception('No permission');
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
        !empty($_POST['customer_id']) ? $_POST['customer_id'] : $current['customer_id'],
        isset($_POST['device_model']) ? $_POST['device_model'] : $current['device_model'],
        isset($_POST['device_brand']) ? $_POST['device_brand'] : $current['device_brand'],
        isset($_POST['device_type']) ? $_POST['device_type'] : $current['device_type'],
        isset($_POST['order_type']) ? $_POST['order_type'] : $current['order_type'],
        isset($_POST['status']) ? $_POST['status'] : $current['status'],
        (isset($_POST['technician_id']) && $_POST['technician_id'] !== '') ? $_POST['technician_id'] : $current['technician_id'],
        isset($_POST['estimated_cost']) ? $_POST['estimated_cost'] : $current['estimated_cost'],
        isset($_POST['final_cost']) ? $_POST['final_cost'] : $current['final_cost'],
        isset($_POST['extra_expenses']) ? $_POST['extra_expenses'] : $current['extra_expenses'],
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

    $new_status = $_POST['status'] ?? $current['status'];
    $technician_id = $_POST['technician_id'] ?? $current['technician_id'];
    $final_cost = isset($_POST['final_cost']) ? (float)$_POST['final_cost'] : (float)$current['final_cost'];

    $finishing_statuses = ['Completed', 'Collected'];
    $was_finished = in_array($current['status'], $finishing_statuses, true);
    $is_finishing = in_array($new_status, $finishing_statuses, true);

    if ($current['status'] === 'Collected' && $new_status !== 'Collected') {
        throw new Exception('Cannot change status after Collected');
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

            if ($new_status === 'Completed' && get_setting('acc_auto_create_invoice', '0') == '1') {
                require_once '../models/InvoiceManager.php';
                $manager = new InvoiceManager($pdo);
                $check = $pdo->prepare('SELECT id FROM invoices WHERE order_id = ?');
                $check->execute([$order_id]);
                if (!$check->fetch()) {
                    $stmt_ord = $pdo->prepare('SELECT o.*, c.first_name, c.last_name, c.company FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?');
                    $stmt_ord->execute([$order_id]);
                    $orderData = $stmt_ord->fetch();

                    if ($orderData) {
                        $prefix = get_setting('acc_invoice_prefix', date('Y'));
                        $count = $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
                        $inv_number = $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

                        $final_price = (float)($_POST['final_cost'] ?? ($orderData['final_cost'] ?: $orderData['estimated_cost']));

                        $invoiceData = [
                            'invoice_number' => $inv_number,
                            'customer_id' => $orderData['customer_id'],
                            'order_id' => $order_id,
                            'date_issue' => date('Y-m-d'),
                            'date_tax' => date('Y-m-d'),
                            'date_due' => date('Y-m-d', strtotime('+14 days')),
                            'status' => 'issued',
                            'payment_method' => 'bank_transfer',
                            'currency' => get_setting('currency', 'Kč'),
                            'is_vat_payer' => get_setting('acc_is_vat_payer', '0'),
                            'items' => [
                                [
                                    'name' => 'Oprava ' . $orderData['device_brand'] . ' ' . $orderData['device_model'],
                                    'quantity' => 1,
                                    'unit' => 'ks',
                                    'price' => $final_price,
                                    'vat_rate' => get_setting('acc_vat_rate', '21')
                                ]
                            ]
                        ];
                        $manager->saveInvoice($invoiceData);
                    }
                }
            }
        } elseif ($was_finished && !$is_finishing) {
            processOrderInventoryChange($order_id, $is_finishing, $was_finished);
        }
        logOrderStatusChange($order_id, $current['status'], $new_status);
    }

    $pdo->commit();

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['success' => true]);
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
