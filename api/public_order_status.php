<?php
/**
 * Public order status by opaque 8-char token (no session auth).
 * Used by app.servis.expert/status.php after scanning the reception QR.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'auth' => false,
    'post' => false,
    'csrf' => false,
    'rate' => ['action' => 'public_order_status', 'max' => 30, 'window' => 60],
    'json' => true,
]);

$token = crmNormalizePublicStatusToken($_GET['id'] ?? $_GET['token'] ?? '');
if ($token === '') {
    api_json_exit(['success' => false, 'message' => 'Invalid status token.'], 400);
}

$payload = crmGetPublicOrderStatusByToken($pdo, $token);
if ($payload === null) {
    api_json_exit(['success' => false, 'message' => 'Order not found.'], 404);
}

api_json_exit([
    'success' => true,
    'order' => $payload,
]);
