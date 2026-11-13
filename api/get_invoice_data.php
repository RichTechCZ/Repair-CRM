<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/InvoiceAutomation.php';
require_once __DIR__ . '/../models/InvoicePolicy.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'permission' => 'admin_access',
    'rate' => 'get_invoice_data',
]);

$order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
if (!$order_id || $order_id <= 0) {
    api_json_exit(['success' => false, 'message' => 'Missing or invalid order ID'], 400);
}

try {
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company, c.ico, c.dic 
                           FROM orders o 
                           JOIN customers c ON o.customer_id = c.id 
                           WHERE o.id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        api_json_exit(['success' => false, 'message' => 'Order not found'], 404);
    }
    unset($order['pin_code'], $order['pin_code_encrypted']);

    // Suggestion from the shared invoice series; create_invoice.php reserves under lock.
    $invoice_number = crmSuggestInvoiceNumber($pdo);

    // The stored final cost is the customer charge. Do not add order item prices:
    // they may already be included and would double-count invoice revenue.
    $total_amount = resolveInvoiceTotal($order['final_cost'] ?? null, $order['estimated_cost'] ?? 0);

    api_json_exit([
        'success' => true,
        'order' => $order,
        'next_invoice_number' => $invoice_number,
        'variable_symbol' => $invoice_number, // Default VS to invoice number
        'date_issue' => date('Y-m-d'),
        'date_tax' => date('Y-m-d'),
        'date_due' => date('Y-m-d', strtotime('+14 days')),
        'total_amount' => $total_amount
    ]);
} catch (Exception $e) {
    api_exception_exit($e);
}
