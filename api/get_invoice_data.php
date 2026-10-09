<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../models/InvoiceAutomation.php';
require_once __DIR__ . '/../models/InvoicePolicy.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!hasPermission('admin_access')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing order ID']);
    exit;
}

try {
    $order_id = $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company, c.ico, c.dic 
                           FROM orders o 
                           JOIN customers c ON o.customer_id = c.id 
                           WHERE o.id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();

    if (!$order) {
        throw new Exception('Order not found');
    }
    unset($order['pin_code']);

    // Suggestion from the shared invoice series; create_invoice.php reserves under lock.
    $invoice_number = crmSuggestInvoiceNumber($pdo);

    // The stored final cost is the customer charge. Do not add order item prices:
    // they may already be included and would double-count invoice revenue.
    $total_amount = resolveInvoiceTotal($order['final_cost'] ?? null, $order['estimated_cost'] ?? 0);

    echo json_encode([
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
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
