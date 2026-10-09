<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'add_inventory',
]);
$part_name = trim((string)($_POST['part_name'] ?? ''));
$sku = trim((string)($_POST['sku'] ?? ''));
$quantity_raw = $_POST['quantity'] ?? 0;
$cost_raw = $_POST['cost_price'] ?? '';
$sale_raw = $_POST['sale_price'] ?? 0;
$min_raw = $_POST['min_stock'] ?? 5;

if ($part_name === '') {
    echo json_encode(['success' => false, 'message' => 'Part name is required']);
    exit;
}
if (!is_numeric($quantity_raw) || !is_finite((float)$quantity_raw) || (float)$quantity_raw < 0 || floor((float)$quantity_raw) != (float)$quantity_raw) {
    echo json_encode(['success' => false, 'message' => 'Invalid quantity']);
    exit;
}
if (($cost_raw !== '' && (!is_numeric($cost_raw) || !is_finite((float)$cost_raw) || (float)$cost_raw < 0))
    || !is_numeric($sale_raw) || !is_finite((float)$sale_raw) || (float)$sale_raw < 0
    || !is_numeric($min_raw) || !is_finite((float)$min_raw) || (float)$min_raw < 0 || floor((float)$min_raw) != (float)$min_raw) {
    echo json_encode(['success' => false, 'message' => 'Invalid numeric fields']);
    exit;
}
$quantity = (int)$quantity_raw;
// Unknown purchase cost stays NULL so payroll falls back to the selling price (binding formula).
$cost_price = $cost_raw === '' ? null : (float)$cost_raw;
$sale_price = (float)$sale_raw;
$min_stock = (int)$min_raw;

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
