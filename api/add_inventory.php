<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'add_inventory',
]);
$part_name = trim($_POST['part_name'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$quantity = (float)($_POST['quantity'] ?? 0);
$cost_price = (float)($_POST['cost_price'] ?? 0);
$sale_price = (float)($_POST['sale_price'] ?? 0);
$min_stock = (float)($_POST['min_stock'] ?? 5);

if ($part_name === '') {
    echo json_encode(['success' => false, 'message' => 'Part name is required']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$part_name, $sku, $quantity, $cost_price, $sale_price, $min_stock]);

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        echo json_encode(['success' => true, 'message' => 'Inventory added']);
    } else {
        header("Location: ../inventory.php");
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
