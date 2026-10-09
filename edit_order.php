<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/upload_security.php';
require_once 'models/OrderStatusService.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) die(__('order_id_missing'));

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die(__('order_not_found'));

if (!currentUserCanEditOrder($id)) {
    die(__('no_edit_permission'));
}

// Fetch current customer for the remote customer selector
$customer_stmt = $pdo->prepare("SELECT id, first_name, last_name, phone, company FROM customers WHERE id = ?");
$customer_stmt->execute([$order['customer_id']]);
$current_customer = $customer_stmt->fetch();

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_token_invalid');
    } else {
        $storedUploadPaths = [];
        try {
            $pdo->beginTransaction();
            $stmt_curr = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt_curr->execute([$id]);
            $current_order = $stmt_curr->fetch();
            if (!$current_order || !currentUserCanEditOrder($id)) {
                throw new Exception(__('no_edit_permission'));
            }

            $is_admin = hasPermission('admin_access');
            $canonical_status = canonicalOrderStatus((string)($_POST['status'] ?? $current_order['status']));
            if (!in_array($canonical_status, getAllStatuses(), true)) {
                throw new InvalidArgumentException('Invalid status.');
            }
            $status = getOrderStatusStorageValue($canonical_status);
            $estimated_cost_raw = $_POST['estimated_cost'] ?? $current_order['estimated_cost'];
            if (
                !is_numeric($estimated_cost_raw) ||
                !is_finite((float)$estimated_cost_raw) ||
                (float)$estimated_cost_raw < 0
            ) {
                throw new InvalidArgumentException(__('missing_data'));
            }

            $canonical_current = canonicalOrderStatus($current_order['status']);
            OrderStatusService::assertCanChangeFromTerminal(
                $canonical_current,
                $canonical_status,
                $is_admin
            );

            // Same closed-order lock as api/update_order_full.php: on a terminal order only admins
            // may change revenue inputs (estimated cost is the revenue fallback; order type decides
            // whether a final cost is required).
            $posted_order_type = trim((string)($_POST['order_type'] ?? $current_order['order_type']));
            $money_changed = abs((float)$estimated_cost_raw - (float)($current_order['estimated_cost'] ?? 0)) > 0.004
                || $posted_order_type !== (string)($current_order['order_type'] ?? 'Non-Warranty');
            if ($money_changed) {
                OrderStatusService::assertClosedOrderEditable($canonical_current, $is_admin);
            }

            // Handover requirements guard the transition into Issued (or a change of what they were
            // validated on); notes on an already issued legacy order must stay editable.
            if ($canonical_status === 'Issued' && ($canonical_current !== 'Issued' || $money_changed)) {
                OrderStatusService::assertIssuedRequirements(
                    $canonical_status,
                    $current_order['final_cost'] ?? null,
                    $current_order['shipping_method'] ?? null,
                    true,
                    $posted_order_type
                );
            }
            OrderStatusService::assertCancellationReason(
                $canonical_status,
                null,
                tableColumnExists('orders', 'cancellation_reason'),
                true,
                $current_order['cancellation_reason'] ?? null
            );

            $customer_id = $is_admin
                ? filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT)
                : (int)$current_order['customer_id'];
            if (!$customer_id) {
                throw new InvalidArgumentException(__('missing_data'));
            }
            $technician_id = $is_admin
                ? (filter_var($_POST['technician_id'] ?? null, FILTER_VALIDATE_INT) ?: null)
                : $current_order['technician_id'];

            $shipping_date_sql = $canonical_status === 'Issued' && empty($current_order['shipping_date'])
                ? ', shipping_date = CURRENT_TIMESTAMP'
                : '';

            $update = $pdo->prepare("UPDATE orders SET
                customer_id = ?,
                technician_id = ?,
                device_type = ?,
                order_type = ?,
                device_brand = ?,
                device_model = ?,
                serial_number = ?,
                serial_number_2 = ?,
                problem_description = ?,
                technician_notes = ?,
                estimated_cost = ?,
                status = ?,
                updated_at = CURRENT_TIMESTAMP
                $shipping_date_sql
                WHERE id = ?");
            $update->execute([
                $customer_id,
                $technician_id,
                trim((string)($_POST['device_type'] ?? $current_order['device_type'])),
                $posted_order_type,
                trim((string)($_POST['device_brand'] ?? $current_order['device_brand'])),
                trim((string)($_POST['device_model'] ?? $current_order['device_model'])),
                trim((string)($_POST['serial_number'] ?? $current_order['serial_number'])),
                trim((string)($_POST['serial_number_2'] ?? $current_order['serial_number_2'])),
                trim((string)($_POST['problem_description'] ?? $current_order['problem_description'])),
                trim((string)($_POST['technician_notes'] ?? $current_order['technician_notes'])),
                (float)$estimated_cost_raw,
                $status,
                $id
            ]);

            $effects = OrderStatusService::applyInTransaction(
                $pdo,
                (int)$id,
                $current_order['status'],
                $status,
                $current_order['final_cost'] ?? null
            );

            if (!empty($_FILES['files']['name'][0])) {
                $uploadResult = crmStoreOrderUploads($pdo, (int)$id, $_FILES['files']);
                $storedUploadPaths = $uploadResult['paths'];
            }

            saveDeviceModelUsage(
                trim((string)($_POST['device_brand'] ?? $current_order['device_brand'])),
                trim((string)($_POST['device_model'] ?? $current_order['device_model']))
            );
            $pdo->commit();

            OrderStatusService::afterCommit(
                $pdo,
                (int)$id,
                $current_order['status'],
                $status,
                $current_order['final_cost'] ?? null,
                $effects['invoice_to_sync']
            );
            OrderStatusService::notifyTechnicianReassignment(
                $pdo,
                (int)$id,
                $current_order['technician_id'],
                $technician_id,
                $current_order
            );

            $success = __('order_updated_success');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            crmRemoveStoredUploadPaths($storedUploadPaths);
            error_log('edit_order error: ' . $e->getMessage());
            $error = __('update_error') . ' ' . publicExceptionMessage($e);
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center">
        <a href="view_order.php?id=<?php echo (int)$order['id']; ?>" class="btn btn-outline-secondary btn-sm me-3"
           data-crm-action="navigate-back" data-crm-explicit-return="0">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> <?php echo __('back'); ?>
        </a>
        <h1 class="mb-0"><?php echo __('edit_order_header'); ?><?php echo $order['id']; ?></h1>
    </div>
    <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-secondary">
        <i class="fas fa-eye me-2"></i> <?php echo __('view'); ?>
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-user me-2 text-primary"></i><?php echo __('client'); ?></label>
                    <select name="customer_id" class="form-select select2-customer-remote" required>
                        <?php
                        $edit_customer_name = trim(($current_customer['last_name'] ?? '') . ' ' . ($current_customer['first_name'] ?? ''));
                        $edit_customer_company = trim($current_customer['company'] ?? '');
                        if ($edit_customer_company !== '') {
                            $edit_customer_name = $edit_customer_company . ($edit_customer_name !== '' ? ' (' . $edit_customer_name . ')' : '');
                        }
                        $edit_customer_label = $edit_customer_name . (!empty($current_customer['phone']) ? ' (' . $current_customer['phone'] . ')' : '');
                        ?>
                        <option value="<?php echo (int)$order['customer_id']; ?>" selected>
                            <?php echo htmlspecialchars($edit_customer_label); ?>
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-user-cog me-2 text-info"></i><?php echo __('technician'); ?></label>
                    <select name="technician_id" class="form-select">
                        <option value="">-- <?php echo __('technician'); ?> --</option>
                        <?php 
                        $techs = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
                        foreach($techs as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php if($order['technician_id']==$t['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-tasks me-2 text-warning"></i><?php echo __('status'); ?></label>
                    <select name="status" class="form-select">
                        <?php foreach (getAllStatuses() as $status_option): ?>
                            <option value="<?php echo e($status_option); ?>" <?php echo canonicalOrderStatus($order['status'] ?? '') === $status_option ? 'selected' : ''; ?>>
                                <?php echo e(getStatusLabel($status_option)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-laptop-medical me-2 text-secondary"></i><?php echo __('device_type'); ?></label>
                    <select name="device_type" class="form-select">
                        <option value="Phone" <?php if($order['device_type']=='Phone') echo 'selected'; ?>><?php echo __('phone_type'); ?></option>
                        <option value="Notebook" <?php if($order['device_type']=='Notebook') echo 'selected'; ?>><?php echo __('notebook_type'); ?></option>
                        <option value="Tablet" <?php if($order['device_type']=='Tablet') echo 'selected'; ?>><?php echo __('tablet_type'); ?></option>
                        <option value="Other" <?php if($order['device_type']=='Other') echo 'selected'; ?>><?php echo __('other_type'); ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-file-contract me-2 text-primary"></i><?php echo __('warranty_type'); ?></label>
                    <select name="order_type" class="form-select">
                        <option value="Non-Warranty" <?php if($order['order_type']=='Non-Warranty') echo 'selected'; ?>><?php echo __('warranty_no'); ?></option>
                        <option value="Warranty" <?php if($order['order_type']=='Warranty') echo 'selected'; ?>><?php echo __('warranty_yes'); ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-tag me-2 text-info"></i><?php echo __('device_brand'); ?></label>
                    <select name="device_brand" class="form-select select2-brand">
                        <?php foreach(getDeviceBrands() as $brand): ?>
                            <option value="<?php echo $brand; ?>" <?php echo ($brand == $order['device_brand']) ? 'selected' : ''; ?>><?php echo $brand; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-mobile-alt me-2 text-dark"></i><?php echo __('device_model'); ?></label>
                    <input type="text" name="device_model" class="form-control" value="<?php echo htmlspecialchars($order['device_model']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-barcode me-2 text-muted"></i><?php echo __('serial'); ?></label>
                    <input type="text" name="serial_number" class="form-control" value="<?php echo htmlspecialchars($order['serial_number']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-barcode me-2 text-muted"></i><?php echo __('serial_2'); ?></label>
                    <input type="text" name="serial_number_2" class="form-control" value="<?php echo htmlspecialchars($order['serial_number_2'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-money-bill-wave me-2 text-success"></i><?php echo __('cost_est'); ?></label>
                    <div class="input-group">
                        <input type="number" name="estimated_cost" class="form-control" step="0.01" value="<?php echo $order['estimated_cost']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-exclamation-triangle me-2 text-danger"></i><?php echo __('problem'); ?></label>
                    <textarea name="problem_description" class="form-control" rows="3"><?php echo htmlspecialchars($order['problem_description']); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-comment-alt me-2 text-info"></i><?php echo __('notes'); ?></label>
                    <textarea name="technician_notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['technician_notes']); ?></textarea>
                </div>
                <div class="col-12 mt-3">
                    <label class="form-label"><i class="fas fa-images me-2 text-info"></i><?php echo __('media_files'); ?></label>
                    <div class="row g-2">
                        <?php 
                        $stmt_media = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
                        $stmt_media->execute([$id]);
                        $attachments = $stmt_media->fetchAll();
                        if (empty($attachments)): ?>
                            <div class="col-12 text-muted small"><?php echo __('no_media_files'); ?></div>
                        <?php else: ?>
                            <?php foreach ($attachments as $file): 
                                $isVideo = strpos($file['file_type'], 'video') !== false;
                            ?>
                                <div class="col-3 col-md-2" id="media-item-<?php echo $file['id']; ?>">
                                    <div class="card h-100 shadow-sm border position-relative">
                                        <button type="button" class="btn btn-danger btn-sm media-tile__remove position-absolute top-0 end-0 m-1 z-3" data-crm-action="delete-media" data-crm-id="<?php echo (int)$file['id']; ?>" aria-label="<?php echo e(__('delete') . ': ' . $file['file_name']); ?>">
                                            <i class="fas fa-times" aria-hidden="true"></i>
                                        </button>
                                        <div class="ratio ratio-1x1 bg-dark bg-opacity-25">
                                            <?php if ($isVideo): ?>
                                                <div class="d-flex align-items-center justify-content-center bg-dark"><i class="fas fa-video text-white"></i></div>
                                            <?php else: ?>
                                                <img src="<?php echo e(crmOrderAttachmentUrl((int)$file['id'])); ?>" class="object-fit-cover" alt="<?php echo e(__('attachment_photo_alt')); ?>">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-upload me-2 text-primary"></i><?php echo __('add_media_files'); ?></label>
                    <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                    <div class="form-text"><?php echo __('media_files_hint'); ?></div>
                </div>
                <div class="col-12 mt-4 d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    <button type="button" class="btn btn-outline-danger" data-crm-action="delete-order" data-crm-id="<?php echo (int)$id; ?>"><?php echo __('delete'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php $crmJsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE; ?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
$(document).ready(function() {
    $('.select2-customer-remote').select2({
        placeholder: <?php echo json_encode(__('search_client_placeholder'), $crmJsFlags); ?>,
        minimumInputLength: 0,
        ajax: {
            url: 'api/search_customers.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term || '', page: params.page || 1 };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.results, pagination: { more: !!(data.pagination && data.pagination.more) } };
            }
        },
        width: '100%'
    });

    $('.select2-brand').select2({
        placeholder: <?php echo json_encode(__('brand_placeholder'), $crmJsFlags); ?>,
        tags: true,
        width: '100%'
    });
});

function deleteOrder(id) {
    showConfirm(<?php echo json_encode(__('confirm_delete_order_full'), $crmJsFlags); ?>, function() {
        $.post('api/delete_order.php', {
            id: id,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        }, function(res) {
            if (res.success) {
                showAlert(<?php echo json_encode(__('order_deleted'), $crmJsFlags); ?>);
                window.location.href = 'orders.php';
            } else {
                showAlert(<?php echo json_encode(__('error') . ': ', $crmJsFlags); ?> + res.message);
            }
        });
    }, undefined, 'danger');
}

function deleteMedia(id) {
    const mediaNode = $('#media-item-' + id);
    const requestData = {
        id: id,
        csrf_token: $('meta[name="csrf-token"]').attr('content')
    };

    showConfirm(<?php echo json_encode(__('confirm_delete_file'), $crmJsFlags); ?>, function() {
        $.ajax({
            url: 'api/delete_media.php',
            type: 'POST',
            dataType: 'json',
            data: requestData,
            success: function(res) {
                if (res && res.success) {
                    mediaNode.fadeOut(180, function() {
                        $(this).remove();
                    });
                } else {
                    const message = (res && res.message) ? res.message : <?php echo json_encode(__('error'), $crmJsFlags); ?>;
                    showAlert(<?php echo json_encode(__('error') . ': ', $crmJsFlags); ?> + message);
                }
            },
            error: function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : <?php echo json_encode(__('error'), $crmJsFlags); ?>;
                showAlert(<?php echo json_encode(__('error') . ': ', $crmJsFlags); ?> + message);
            }
        });
    }, undefined, 'danger');
}
</script>

<?php require_once 'includes/footer.php'; ?>
