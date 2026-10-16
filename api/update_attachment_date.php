<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'update_attachment_date',
]);
$attachment_id = (int)($_POST['attachment_id'] ?? 0);
$created_at = OrderStatusService::parseManualStatusDate($_POST['created_at'] ?? '');

if ($attachment_id <= 0 || $created_at === null) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT a.order_id, o.status
                           FROM order_attachments a
                           JOIN orders o ON a.order_id = o.id
                           WHERE a.id = ?
                           FOR UPDATE");
    $stmt->execute([$attachment_id]);
    $data = $stmt->fetch();

    if (!$data) {
        throw new Exception('Attachment not found');
    }

    if (!currentUserCanEditOrder($data['order_id'])) {
        throw new Exception(__('access_denied_msg'));
    }

    OrderStatusService::assertClosedOrderEditable(
        canonicalOrderStatus((string)($data['status'] ?? '')),
        hasPermission('admin_access')
    );

    $upd = $pdo->prepare("UPDATE order_attachments SET created_at = ? WHERE id = ?");
    $upd->execute([$created_at, $attachment_id]);
    $pdo->commit();

    api_json_exit(['success' => true, 'message' => 'Attachment date updated']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
