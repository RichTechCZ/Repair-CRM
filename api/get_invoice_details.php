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

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing invoice ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.company 
                           FROM invoices i 
                           JOIN customers c ON i.customer_id = c.id 
                           WHERE i.id = ?");
    $stmt->execute([$_GET['id']]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    $stmt_items = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt_items->execute([$_GET['id']]);
    $items = $stmt_items->fetchAll();

    echo json_encode([
        'success' => true,
        'invoice' => $invoice,
        'items' => $items,
        'currency' => get_setting('currency', 'Kč')
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
