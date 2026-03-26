<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $order_id = $_POST['order_id'] ?? null;
        $invoice_number = $_POST['invoice_number'] ?? '';
        $variable_symbol = $_POST['variable_symbol'] ?? '';
        $date_issue = $_POST['date_issue'] ?? date('Y-m-d');
        $date_tax = $_POST['date_tax'] ?? date('Y-m-d');
        $date_due = $_POST['date_due'] ?? date('Y-m-d');
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        
        $is_vat_payer = get_setting('acc_is_vat_payer', '0') == '1';
        $vat_rate = (float)get_setting('acc_vat_rate', '21');
        $vat_amount = $is_vat_payer ? ($total_amount * ($vat_rate / (100 + $vat_rate))) : 0;
        $currency = get_setting('currency', 'Kč');

        // Get customer_id from order
        $stmt = $pdo->prepare("SELECT customer_id FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $customer_id = $stmt->fetchColumn();

        if (!$customer_id) {
            throw new Exception('Order or Customer not found');
        }

        $stmt = $pdo->prepare("INSERT INTO invoices (invoice_number, variable_symbol, order_id, customer_id, date_issue, date_tax, date_due, total_amount, is_vat_payer, vat_amount, currency) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $invoice_number,
            $variable_symbol,
            $order_id,
            $customer_id,
            $date_issue,
            $date_tax,
            $date_due,
            $total_amount,
            $is_vat_payer ? 1 : 0,
            $vat_amount,
            $currency
        ]);
        $invoice_id = $pdo->lastInsertId();

        // Add dynamic items
        if (isset($_POST['item_name']) && is_array($_POST['item_name'])) {
            $stmt_item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, price) VALUES (?, ?, ?)");
            foreach ($_POST['item_name'] as $index => $name) {
                if (empty(trim((string)$name))) continue;
                $price = (float)($_POST['item_price'][$index] ?? 0);
                $stmt_item->execute([$invoice_id, $name, $price]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Invoice created', 'id' => $invoice_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
