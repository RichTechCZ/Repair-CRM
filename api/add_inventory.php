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

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$part_name = trim($_POST['part_name'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$quantity = (float)($_POST['quantity'] ?? 0);
$cost_price = (float)($_POST['cost_price'] ?? 0);
$sale_price = (float)($_POST['sale_price'] ?? 0);
$min_stock = (float)($_POST['min_stock'] ?? 5);

if (empty($part_name)) {
    echo json_encode(['success' => false, 'message' => 'Part name is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$part_name, $sku, $quantity, $cost_price, $sale_price, $min_stock]);
    
    // Check if called from form (AJAX) or direct
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => true, 'message' => 'Inventory added']);
    } else {
        header("Location: ../inventory.php");
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
