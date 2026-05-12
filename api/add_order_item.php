<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean(); // discard any output/warnings
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
$inventory_id = $_POST['inventory_id'] ?? null;
$qty = $_POST['quantity'] ?? 1;

if (!$order_id || !$inventory_id) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    // Check permissions
    $stmt = $pdo->prepare("SELECT technician_id FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    if (($_SESSION['role'] ?? '') == 'technician' && !hasPermission('view_all_orders') && $order['technician_id'] != ($_SESSION['tech_id'] ?? 0)) {
        throw new Exception(__('access_denied_msg'));
    }

    // Get current price from inventory
    $stmt = $pdo->prepare("SELECT sale_price FROM inventory WHERE id = ?");
    $stmt->execute([$inventory_id]);
    $price = $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price) VALUES (?, ?, ?, ?)");
    $stmt->execute([$order_id, $inventory_id, $qty, $price]);

    // Notify technician about added part
    $stmt = $pdo->prepare("SELECT o.technician_id, t.telegram_id, i.part_name 
                           FROM orders o 
                           LEFT JOIN technicians t ON o.technician_id = t.id 
                           JOIN inventory i ON i.id = ?
                           WHERE o.id = ?");
    $stmt->execute([$inventory_id, $order_id]);
    $notify = $stmt->fetch();
    
    if ($notify && $notify['telegram_id']) {
        $msg = sprintf(__('tg_part_added'), $order_id) . "\n";
        $msg .= sprintf(__('tg_part_added_detail'), $notify['part_name'], $qty) . "\n";
        sendTelegramNotification($notify['telegram_id'], $msg);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
