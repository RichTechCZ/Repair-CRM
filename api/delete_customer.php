<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'edit_customers',
    'rate' => 'delete_customer',
]);
$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID не указан']);
    exit;
}

if (!currentUserCanViewCustomer($id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

try {
    // Check if customer has orders
    $check = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        throw new Exception('Нельзя удалить клиента, у которого есть заказы. Сначала удалите заказы.');
    }

    $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
