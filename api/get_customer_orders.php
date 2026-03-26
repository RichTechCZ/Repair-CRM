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

$customer_id = $_GET['customer_id'] ?? null;
if (!$customer_id) {
    echo json_encode(['success' => false, 'message' => 'No customer ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, device_brand, device_model, status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
