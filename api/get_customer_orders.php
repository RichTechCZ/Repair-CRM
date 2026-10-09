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

$customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
if (!$customer_id || $customer_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No customer ID']);
    exit;
}

if (!currentUserCanViewCustomer($customer_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
    exit;
}

/**
 * `.status-pill--*` variant for a lifecycle status. Derived from getStatusBadge()
 * so the customer-orders modal can never drift from the canonical mapping.
 */
function customerOrderStatusVariant(string $status): string {
    if (preg_match('/status-pill--([a-z-]+)/', getStatusBadge($status), $match)) {
        return $match[1];
    }
    return 'closed';
}

try {
    // Technicians only see their own orders for this customer (no cross-tech leak).
    if (isTechnicianScoped()) {
        $stmt = $pdo->prepare(
            "SELECT id, device_brand, device_model, status, created_at
             FROM orders
             WHERE customer_id = ? AND technician_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$customer_id, currentTechnicianId()]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, device_brand, device_model, status, created_at
             FROM orders
             WHERE customer_id = ?
             ORDER BY created_at DESC"
        );
        $stmt->execute([$customer_id]);
    }
    $orders = array_map(static function (array $order): array {
        return $order + [
            'status_label' => getStatusLabel((string)$order['status']),
            'status_variant' => customerOrderStatusVariant((string)$order['status']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
