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
        $pdo->beginTransaction();

        $invoice_id = $_POST['invoice_id'] ?? null;
        if (!$invoice_id) throw new Exception('Missing invoice ID');

        $invoice_number = $_POST['invoice_number'] ?? '';
        $variable_symbol = $_POST['variable_symbol'] ?? '';
        $date_issue = $_POST['date_issue'] ?? date('Y-m-d');
        $date_tax = $_POST['date_tax'] ?? date('Y-m-d');
        $date_due = $_POST['date_due'] ?? date('Y-m-d');
        $total_amount = (float)($_POST['total_amount'] ?? 0);
        
        $is_vat_payer = get_setting('acc_is_vat_payer', '0') == '1';
        $vat_rate = (float)get_setting('acc_vat_rate', '21');
        $vat_amount = $is_vat_payer ? ($total_amount * ($vat_rate / (100 + $vat_rate))) : 0;

        // Update main invoice data
        $stmt = $pdo->prepare("UPDATE invoices SET 
                                invoice_number = ?, 
                                variable_symbol = ?, 
                                date_issue = ?, 
                                date_tax = ?, 
                                date_due = ?, 
                                total_amount = ?, 
                                vat_amount = ?,
                                updated_at = CURRENT_TIMESTAMP
                               WHERE id = ?");
        $stmt->execute([
            $invoice_number,
            $variable_symbol,
            $date_issue,
            $date_tax,
            $date_due,
            $total_amount,
            $vat_amount,
            $invoice_id
        ]);

        // Delete old items and insert new ones
        $stmt_del = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
        $stmt_del->execute([$invoice_id]);

        if (isset($_POST['item_name']) && is_array($_POST['item_name'])) {
            $stmt_item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, price) VALUES (?, ?, ?)");
            foreach ($_POST['item_name'] as $index => $name) {
                if (empty(trim($name))) continue;
                $price = (float)($_POST['item_price'][$index] ?? 0);
                $stmt_item->execute([$invoice_id, $name, $price]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Invoice updated']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
