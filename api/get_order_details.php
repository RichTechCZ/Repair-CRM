<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/../includes/upload_security.php';

ob_clean(); // discard any output/warnings
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name 
                           FROM orders o 
                           JOIN customers c ON o.customer_id = c.id 
                           LEFT JOIN technicians t ON o.technician_id = t.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }

    if (!currentUserCanViewOrder($id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
        exit;
    }

    crmDecryptDevicePinInRow($order);

    // Fetch attachments
    $stmt = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    // Raw storage paths stay server-side; the browser gets the authorized media URL.
    $attachments = array_map(static function (array $file): array {
        return [
            'id' => (int)$file['id'],
            'file_type' => (string)$file['file_type'],
            'file_name' => (string)$file['file_name'],
            'url' => crmOrderAttachmentUrl((int)$file['id']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Fetch parts
    $stmt = $pdo->prepare(
        "SELECT oi.*, COALESCE(NULLIF(oi.part_name, ''), i.part_name) AS part_name
         FROM order_items oi
         LEFT JOIN inventory i ON oi.inventory_id = i.id
         WHERE oi.order_id = ?"
    );
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'order' => $order,
        'attachments' => $attachments,
        'items' => $items,
        'role' => hasPermission('admin_access') ? 'admin' : 'technician'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>

