<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
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

    $prefix = get_setting('acc_invoice_prefix', date('Y'));
    $next_num = get_setting('acc_invoice_next_number', '1');
    // Format: Prefix + 4 digits (e.g. 20260001)
    $invoice_number = $prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);

    echo json_encode([
        'success' => true,
        'order' => $order,
        'next_invoice_number' => $invoice_number,
        'variable_symbol' => $invoice_number, // Default VS to invoice number
        'date_issue' => date('Y-m-d'),
        'date_tax' => date('Y-m-d'),
        'date_due' => date('Y-m-d', strtotime('+14 days')),
        'total_amount' => $order['final_cost'] ?: $order['estimated_cost']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
