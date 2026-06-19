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
$mode = $_POST['mode'] ?? 'inventory';
$manual_part_name = trim($_POST['part_name'] ?? '');
$manual_source = trim($_POST['source'] ?? '');
$manual_price = $_POST['price'] ?? null;

if (!$order_id || ($mode === 'inventory' && !$inventory_id)) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

if ($mode === 'manual' && ($manual_part_name === '' || $manual_source === '' || $manual_price === null || $manual_price === '')) {
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
    
    if (!currentUserCanEditOrder($order_id)) {
        throw new Exception(__('access_denied_msg'));
    }

    if ($mode === 'manual') {
        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, part_name, source, quantity, price) VALUES (?, NULL, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $manual_part_name, $manual_source, $qty, $manual_price]);
    } else {
        // Get current price from inventory
        $stmt = $pdo->prepare("SELECT sale_price, part_name FROM inventory WHERE id = ?");
        $stmt->execute([$inventory_id]);
        $inventory_item = $stmt->fetch();
        if (!$inventory_item) {
            throw new Exception(__('not_found'));
        }

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, $inventory_id, $qty, $inventory_item['sale_price']]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
