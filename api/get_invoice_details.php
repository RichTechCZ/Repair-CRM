<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => false,
    'csrf' => false,
    'permission' => 'admin_access',
    'rate' => 'get_invoice_details',
]);

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
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
