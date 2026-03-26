<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean(); // discard any output/warnings
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    echo json_encode(['success' => false, 'message' => __('access_denied_simple')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
$invoice_id = $_POST['invoice_id'] ?? null;
$invoice_number = $_POST['invoice_number'] ?? null;
$item_name = $_POST['item_name'] ?? '';
$date_issue = $_POST['date_issue'] ?? date('Y-m-d');
$date_due = $_POST['date_due'] ?? date('Y-m-d', strtotime('+14 days'));
$total_amount = floatval($_POST['total_amount'] ?? 0);

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Не указан заказ']);
    exit;
}

try {
    // Get order and customer data
    $stmt = $pdo->prepare("SELECT o.*, c.* FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден']);
        exit;
    }
    
    $pdo->beginTransaction();

    $is_vat_payer = get_setting('acc_is_vat_payer', '0') == '1';
    $vat_rate = $is_vat_payer ? get_setting('acc_vat_rate', '21') : 0;
    
    if (!empty($invoice_id)) {
        // UPDATE existing invoice
        $stmt = $pdo->prepare("UPDATE invoices SET 
            invoice_number = ?, 
            variable_symbol = ?, 
            date_issue = ?, 
            date_tax = ?, 
            date_due = ?, 
            total_amount = ?,
            notes = ?,
            status = ?,
            payment_method = ?,
            payment_date = ?
            WHERE id = ?");
        
        $status = $_POST['status'] ?? 'issued';
        $payment_date = ($status == 'paid') ? date('Y-m-d') : null;

        $stmt->execute([
            $invoice_number,
            $invoice_number,
            $date_issue,
            $date_issue,
            $date_due,
            $total_amount,
            $item_name,
            $status,
            $_POST['payment_method'] ?? 'bank_transfer',
            $payment_date,
            $invoice_id
        ]);
        
        // Update invoice item
        $stmt_check = $pdo->prepare("SELECT id FROM invoice_items WHERE invoice_id = ?");
        $stmt_check->execute([$invoice_id]);
        $existing_item = $stmt_check->fetch();
        
        if ($existing_item) {
            $pdo->prepare("UPDATE invoice_items SET item_name = ?, price = ? WHERE invoice_id = ?")
                ->execute([$item_name, $total_amount, $invoice_id]);
        } else {
            $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, 'ks', ?, ?)")
                ->execute([$invoice_id, $item_name, $total_amount, $vat_rate]);
        }
        
        $pdo->commit();
        
        // ← Always sync orders.final_cost with invoice total
        $pdo->prepare("UPDATE orders SET final_cost = ? WHERE id = ?")
            ->execute([$total_amount, $order_id]);

        echo json_encode([
            'success' => true, 
            'id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'action' => 'updated'
        ]);
    } else {
        // CREATE new invoice
        if (empty($invoice_number)) {
            $invoice_number = $order_id;
        }
        
        // Check if invoice number exists
        $stmt_check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ?");
        $stmt_check->execute([$invoice_number]);
        if ($stmt_check->fetch()) {
            $stmt_max = $pdo->query("SELECT MAX(CAST(invoice_number AS UNSIGNED)) as max_num FROM invoices WHERE invoice_number REGEXP '^[0-9]+$'");
            $max = $stmt_max->fetch()['max_num'] ?? 0;
            $invoice_number = $max + 1;
        }
        
        // Get currency from settings
        $currency = get_setting('currency', 'Kč');
        
        $stmt = $pdo->prepare("INSERT INTO invoices 
            (order_id, customer_id, invoice_number, variable_symbol, date_issue, date_tax, date_due, total_amount, currency, status, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, NOW())");
        
        $stmt->execute([
            $order_id,
            $order['customer_id'],
            $invoice_number,
            $invoice_number,
            $date_issue,
            $date_issue,
            $date_due,
            $total_amount,
            $currency,
            $item_name
        ]);
        
        $invoice_id = $pdo->lastInsertId();
        
        // Create invoice item
        $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, 'ks', ?, ?)")
            ->execute([$invoice_id, $item_name, $total_amount, $vat_rate]);
        
        $pdo->commit();
        
        // ← Always sync orders.final_cost with invoice total
        $pdo->prepare("UPDATE orders SET final_cost = ? WHERE id = ?")
            ->execute([$total_amount, $order_id]);

        echo json_encode([
            'success' => true, 
            'id' => $invoice_id,
            'invoice_number' => $invoice_number,
            'action' => 'created'
        ]);
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Express Invoice Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
