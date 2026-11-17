<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/upload_security.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'upload_media',
]);

try {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }

    if (!currentUserCanEditOrder($order_id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
        exit;
    }

    $orderStmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
    $orderStmt->execute([$order_id]);
    $orderStatus = $orderStmt->fetchColumn();
    if ($orderStatus === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => __('order_not_found')]);
        exit;
    }
    OrderStatusService::assertClosedOrderEditable(
        canonicalOrderStatus((string)$orderStatus),
        hasPermission('admin_access')
    );

    if (empty($_FILES['files']['name'][0])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No files uploaded']);
        exit;
    }

    $result = crmStoreOrderUploads($pdo, (int)$order_id, $_FILES['files']);

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode([
        'success' => true,
        'count' => $result['stored'],
        'rejected' => $result['rejected'],
    ]);
} catch (Throwable $e) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
