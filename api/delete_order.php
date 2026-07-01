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

$id = $_POST['id'] ?? $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => __('id_missing')]);
    exit;
}

// Fetch order to check permissions
try {
    $stmt = $pdo->prepare("SELECT status, technician_id FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => __('order_not_found')]);
        exit;
    }

    // Deletion is an administrative, financially sensitive action.
    if (!hasPermission('admin_access')) {
        echo json_encode(['success' => false, 'message' => __('no_delete_permission')]);
        exit;
    }

    $pdo->beginTransaction();

    // Block deletion when the order still has active (non-cancelled) financial records.
    $inv_check = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE order_id = ? AND status <> 'cancelled'");
    $inv_check->execute([$id]);
    if ((int)$inv_check->fetchColumn() > 0) {
        throw new Exception(__('no_delete_permission'));
    }

    // Return consumed parts to stock before removing the order. Only orders that were
    // in a stock-consuming state (repaired/handed over) have actually written off stock.
    if (in_array(canonicalOrderStatus($order['status']), ['Ready', 'Issued'], true)) {
        $stmt_items = $pdo->prepare('SELECT inventory_id, quantity FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL');
        $stmt_items->execute([$id]);
        foreach ($stmt_items->fetchAll() as $item) {
            changeInventoryQuantity($item['inventory_id'], (int)$item['quantity']);
        }
    }

    // 1. Delete linked items
    $stmt1 = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
    $stmt1->execute([$id]);

    // 2. Delete attachments (files)
    $stmt_files = $pdo->prepare("SELECT file_path FROM order_attachments WHERE order_id = ?");
    $stmt_files->execute([$id]);
    $files = $stmt_files->fetchAll();
    foreach ($files as $f) {
        $full_path = '../' . $f['file_path'];
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    $stmt_del_files = $pdo->prepare("DELETE FROM order_attachments WHERE order_id = ?");
    $stmt_del_files->execute([$id]);

    // 3. Delete the order itself
    $stmt2 = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt2->execute([$id]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
