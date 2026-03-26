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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
    exit;
}

try {
    // Get file info and order info to check permissions
    $stmt = $pdo->prepare("SELECT a.file_path, o.technician_id 
                           FROM order_attachments a 
                           JOIN orders o ON a.order_id = o.id 
                           WHERE a.id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch();

    if ($data) {
        if (!hasPermission('edit_orders') && ($data['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0)) {
            throw new Exception(__('access_denied_msg'));
        }

        $full_path = '../' . $data['file_path'];
        if ($data['file_path'] && file_exists($full_path)) {
            unlink($full_path);
        }
        
        $del = $pdo->prepare("DELETE FROM order_attachments WHERE id = ?");
        $del->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('File not found in database');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
