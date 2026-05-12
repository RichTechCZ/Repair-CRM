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

$attachment_id = $_POST['attachment_id'] ?? null;
$created_at = $_POST['created_at'] ?? null;

if (!$attachment_id || !$created_at) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    // Check permissions: only admin or responsible technician can edit
    $stmt = $pdo->prepare("SELECT o.technician_id 
                           FROM order_attachments a 
                           JOIN orders o ON a.order_id = o.id 
                           WHERE a.id = ?");
    $stmt->execute([$attachment_id]);
    $data = $stmt->fetch();

    if (!$data) {
        throw new Exception('Attachment not found');
    }

    if (!hasPermission('edit_orders') && ($data['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0)) {
        throw new Exception(__('access_denied_msg'));
    }

    $upd = $pdo->prepare("UPDATE order_attachments SET created_at = ? WHERE id = ?");
    $upd->execute([$created_at, $attachment_id]);

    echo json_encode(['success' => true, 'message' => 'Attachment date updated']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
