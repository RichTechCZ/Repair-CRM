<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/upload_security.php';
require_once 'models/InvoicePolicy.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) die(__('order_id_missing'));

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.company, t.name as tech_name 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       LEFT JOIN technicians t ON o.technician_id = t.id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die(__('order_not_found'));

if (!currentUserCanViewOrder($id)) {
    die(__('no_edit_permission'));
}
// PIN is encrypted at rest. Decrypt only after authorization; never show ciphertext.
if (!function_exists('crmDecryptDevicePinInRow')) {
    require_once __DIR__ . '/includes/sensitive_data.php';
}
crmDecryptDevicePinInRow($order);
if (function_exists('crmSensitiveDataIsEncrypted') && crmSensitiveDataIsEncrypted((string)($order['pin_code'] ?? ''))) {
    error_log('view_order.php: refusing to render encrypted PIN ciphertext for order #' . (int)$id);
    $order['pin_code'] = '';
    $order['pin_code_decrypt_failed'] = true;
}
$pinDecryptFailed = !empty($order['pin_code_decrypt_failed']);

// Fetch parts linked to this order. Fall back to the pre-migration schema until
// order_items.part_name/source are added on production.
try {
    $stmt = $pdo->prepare("SELECT oi.*, COALESCE(oi.part_name, i.part_name) AS part_name FROM order_items oi LEFT JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $order_items = $stmt->fetchAll();
} catch (PDOException $e) {
    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $order_items = $stmt->fetchAll();
}

// Fetch all available parts for the dropdown (limit 500 max to prevent HTML crash)

// Fetch active technicians for edit modal
$techs = getActiveTechnicians();

$status = canonicalOrderStatus($order['status'] ?? 'Accepted');
$show_shipping = $status === 'Issued';
$show_invoice = hasPermission('admin_access')
    && in_array($status, ['Ready', 'Issued'], true)
    && (($order['final_cost'] ?? 0) > 0 || ($order['estimated_cost'] ?? 0) > 0);
$created_label = date('d.m.Y H:i', strtotime($order['created_at']));

// Fetch status log
$status_log = [];
try {
    ensureOrderStatusLogTable();
    $stmt = $pdo->prepare(
        "SELECT l.*, u.username, t.name AS tech_name
         FROM order_status_log l
         LEFT JOIN users u ON (l.changed_role = 'admin' AND u.id = l.changed_by)
         LEFT JOIN technicians t ON (l.changed_role <> 'admin' AND t.id = l.changed_by)
         WHERE l.order_id = ?
         ORDER BY l.changed_at DESC"
    );
    $stmt->execute([$id]);
    $status_log = $stmt->fetchAll();
} catch (Exception $e) {
    $status_log = [];
}

// "Status date" is when the order entered its current status (order_status_log), not
// orders.updated_at, which MySQL bumps on every field edit.
$status_date = $order['updated_at'];
$current_canonical_status = canonicalOrderStatus((string)$order['status']);
foreach ($status_log as $log_row) {
    if (canonicalOrderStatus((string)$log_row['new_status']) === $current_canonical_status) {
        $status_date = $log_row['changed_at'];
        break;
    }
}
?>

<?php
    // Never use javascript:history.back() — CSP (default-src 'self' / script-src)
    // blocks javascript: URLs, so the Back control appears dead in the browser.
    $back_url = 'orders.php';
    $back_uses_explicit_return = false;
    if (!empty($_GET['return'])) {
        $candidate_back_url = (string)$_GET['return'];
        $is_relative_url = !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $candidate_back_url);
        $has_safe_chars = (bool)preg_match('/^[A-Za-z0-9_\/.\-]+(?:\?[A-Za-z0-9_=&%+.,:\-\/]*)?$/', $candidate_back_url);
        if ($is_relative_url && $has_safe_chars) {
            $back_url = $candidate_back_url;
            $back_uses_explicit_return = true;
        }
    }
?>

<div class="page-header">
    <div class="page-header__copy">
        <div class="page-kicker"><?php echo __('order'); ?></div>
        <h1>#<?php echo $order['id']; ?> · <?php echo htmlspecialchars($order['device_model']); ?></h1>
        <p class="page-subtitle"><?php echo __('created'); ?>: <?php echo $created_label; ?></p>
    </div>
    <div class="page-actions page-actions--order">
        <a href="<?php echo e($back_url); ?>"
           class="btn btn-outline-secondary"
           data-crm-action="navigate-back"
           data-crm-explicit-return="<?php echo $back_uses_explicit_return ? '1' : '0'; ?>">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span><?php echo __('back'); ?></span>
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editOrderFullModal">
            <i class="fas fa-edit" aria-hidden="true"></i>
            <span><?php echo __('edit'); ?></span>
        </button>
        <?php if(hasPermission('admin_access')): ?>
        <button class="btn btn-outline-danger" data-crm-action="delete-order" data-crm-id="<?php echo (int)$order['id']; ?>">
            <i class="fas fa-trash" aria-hidden="true"></i>
            <span><?php echo __('delete'); ?></span>
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card glass-card mb-4 ui-ready">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center py-3">
                <div>
                    <div class="card-eyebrow"><?php echo __('status'); ?></div>
                    <h5 class="mb-0 mt-1"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></h5>
                </div>
                <?php echo getStatusBadge($order['status']); ?>
            </div>
            <div class="card-body">
                <div class="detail-grid mb-4">
                    <div class="detail-card">
                        <h6><?php echo __('client'); ?></h6>
                        <p class="mb-1"><strong><?php echo htmlspecialchars($order['first_name'].' '.$order['last_name']); ?></strong></p>
                        <p class="text-white-75"><i class="fas fa-phone me-2 text-success"></i><?php echo htmlspecialchars($order['phone']); ?></p>
                    </div>
                    <div class="detail-card">
                        <h6><?php echo __('device_model'); ?></h6>
                        <p class="mb-1"><strong><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></strong></p>
                        <p class="text-white-75 mb-1">
                            <?php echo htmlspecialchars(__($order['device_type'])); ?> | 
                            <strong><?php echo $order['order_type'] == 'Warranty' ? __('reclamation') : __('Non-Warranty'); ?></strong>
                        </p>
                        <h6 class="mt-2 mb-1"><?php echo __('serial_numbers'); ?></h6>
                        <p class="text-white-75 mb-0 small">
                            <i class="fas fa-barcode me-1"></i><?php echo __('sn1'); ?>: <?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?>
                        </p>
                        <?php if(!empty($order['serial_number_2'])): ?>
                        <p class="text-white-75 mb-0 small">
                            <i class="fas fa-barcode me-1"></i><?php echo __('sn2'); ?>: <?php echo htmlspecialchars($order['serial_number_2']); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="detail-card">
                        <h6><?php echo __('pin'); ?></h6>
                        <div class="alert alert-warning bg-transparent border border-warning py-2 mb-0">
                            <?php if ($pinDecryptFailed): ?>
                                <span class="text-warning"><?php echo e(__('not_found')); ?> — <?php echo e(__('pin_reenter_notice')); ?></span>
                            <?php else: ?>
                                <code class="text-warning"><?php echo htmlspecialchars($order['pin_code'] ?: '---'); ?></code>
                            <?php endif; ?>
                        </div>
                        <h6 class="mt-3"><?php echo __('technician'); ?></h6>
                        <div class="alert alert-info bg-transparent border border-info py-2 mb-3 text-info">
                            <i class="fas fa-user-cog me-2"></i><strong><?php echo htmlspecialchars($order['tech_name'] ?: '---'); ?></strong>
                        </div>
                        <h6><?php echo __('priority'); ?></h6>
                        <?php if($order['priority'] == 'High'): ?>
                            <span class="status-pill status-pill--priority-high"><?php echo __('high'); ?></span>
                        <?php else: ?>
                            <span class="status-pill status-pill--priority-normal"><?php echo __('normal'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-12">
                        <h6><?php echo __('appearance'); ?></h6>
                        <div class="alert alert-secondary bg-transparent border border-secondary text-white-75 py-2 mb-0 small">
                            <?php echo htmlspecialchars($order['appearance'] ?: '---'); ?>
                        </div>
                    </div>
                </div>

                <h6><?php echo __('problem'); ?></h6>
                <div class="alert alert-light bg-transparent border border-secondary text-white mb-4">
                    <?php echo nl2br(htmlspecialchars($order['problem_description'])); ?>
                </div>

                <?php if(!empty($order['technician_notes'])): ?>
                <h6><?php echo __('notes'); ?></h6>
                <div class="alert alert-info border border-info bg-transparent text-info mb-4 small">
                    <?php echo nl2br(htmlspecialchars($order['technician_notes'])); ?>
                </div>
                <?php endif; ?>

                <h6><?php echo __('status_history'); ?></h6>
                <?php if (!empty($status_log)): ?>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="bg-transparent border-bottom">
                            <tr>
                                <th class="text-white-75"><?php echo __('created'); ?></th>
                                <th class="text-white-75"><?php echo __('status'); ?></th>
                                <th class="text-white-75"><?php echo __('user'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($status_log as $log):
                                $who = $log['username'] ?? $log['tech_name'] ?? '---';
                            ?>
                            <tr>
                                <td class="small text-white-75"><?php echo date('d.m.Y H:i', strtotime($log['changed_at'])); ?></td>
                                <td>
                                    <?php echo getStatusBadge($log['old_status']); ?>
                                    <i class="fas fa-arrow-right mx-1 text-white-75"></i>
                                    <?php echo getStatusBadge($log['new_status']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($who); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="mb-4">
                    <div class="empty-state">
                        <div class="empty-state__mark" aria-hidden="true"></div>
                        <p class="mb-0"><?php echo e(__('not_found')); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Media Section -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><?php echo __('media_files'); ?></h6>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
                        <i class="fas fa-upload me-1" aria-hidden="true"></i> <?php echo __('upload'); ?>
                    </button>
                </div>
                <div class="row g-2 mb-4">
                    <?php 
                    $stmt_files = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ? ORDER BY created_at DESC");
                    $stmt_files->execute([$id]);
                    $attachments = $stmt_files->fetchAll();
                    
                    if(empty($attachments)): ?>
                        <div class="col-12">
                            <div class="empty-state">
                                <div class="empty-state__mark" aria-hidden="true"></div>
                                <p class="mb-0"><?php echo e(__('no_media_files')); ?></p>
                            </div>
                        </div>
                    <?php else:
                        foreach($attachments as $file): 
                            $is_video = strpos($file['file_type'], 'video') !== false;
                    ?>
                        <div class="col-6 col-md-3" id="media-item-<?php echo $file['id']; ?>">
                            <div class="card h-100 shadow-sm border position-relative">
                                <?php if (hasPermission('admin_access')): ?>
                                <button type="button" class="btn btn-sm btn-danger media-tile__remove position-absolute top-0 end-0 m-1 z-3"
                                        data-crm-action="delete-media" data-crm-id="<?php echo (int)$file['id']; ?>"
                                        aria-label="<?php echo e(__('delete') . ': ' . $file['file_name']); ?>">
                                    <i class="fas fa-times" aria-hidden="true"></i>
                                </button>
                                <?php endif; ?>

                                <?php if($is_video): ?>
                                    <a href="<?php echo e(crmOrderAttachmentUrl((int)$file['id'])); ?>" data-fancybox="gallery" data-type="video" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1 bg-dark d-flex align-items-center justify-content-center">
                                            <i class="fas fa-video fa-2x text-white"></i>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo e(crmOrderAttachmentUrl((int)$file['id'])); ?>" data-fancybox="gallery" data-type="image" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1">
                                            <img src="<?php echo e(crmOrderAttachmentUrl((int)$file['id'])); ?>" class="card-img-top object-fit-cover" alt="<?php echo e(__('attachment_photo_alt')); ?>">
                                        </div>
                                    </a>
                                <?php endif; ?>
                                <div class="card-footer p-1 text-center small">
                                    <div class="text-truncate text-white-75" title="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <?php echo htmlspecialchars($file['file_name']); ?>
                                    </div>
                                    <div class="text-white-75 d-flex justify-content-center align-items-center" style="font-size: 0.75rem;">
                                        <i class="far fa-clock me-1"></i>
                                        <span><?php echo date('d.m.Y H:i', strtotime($file['created_at'])); ?></span>
                                        <button type="button" class="btn btn-link p-0 ms-1 text-primary edit-attachment-date"
                                           data-id="<?php echo (int)$file['id']; ?>"
                                           data-date="<?php echo date('Y-m-d\TH:i', strtotime($file['created_at'])); ?>"
                                           aria-label="<?php echo e(__('edit_upload_date')); ?>">
                                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><?php echo __('parts_used'); ?></h6>
                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPartModal">
                        <i class="fas fa-plus me-1"></i> <?php echo __('add_part'); ?>
                    </button>
                </div>
                
                <div class="table-responsive">
                <table class="table table-sm border align-middle table-mobile-cards mb-0">
                    <thead class="bg-transparent border-bottom">
                        <tr>
                            <th><?php echo __('part_name'); ?></th>
                            <th class="text-center"><?php echo __('quantity'); ?></th>
                            <th class="text-end"><?php echo __('price'); ?></th>
                            <th class="text-end"><?php echo __('sum'); ?></th>
                            <th class="text-end" style="width: 80px;"><?php echo __('action'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $parts_total = 0;
                        foreach ($order_items as $item): 
                            $sum = $item['price'] * $item['quantity'];
                            $parts_total += $sum;
                        ?>
                        <tr>
                            <td data-label="<?php echo e(__('part_name')); ?>"><?php echo htmlspecialchars($item['part_name']); ?></td>
                            <td class="text-center" data-label="<?php echo e(__('quantity')); ?>"><?php echo $item['quantity']; ?></td>
                            <td class="text-end" data-label="<?php echo e(__('price')); ?>"><?php echo formatMoney($item['price']); ?></td>
                            <td class="text-end fw-bold" data-label="<?php echo e(__('sum')); ?>"><?php echo formatMoney($sum); ?></td>
                            <td class="text-end mobile-row-actions" data-label="">
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-primary" data-crm-action="edit-order-part" data-crm-item="<?php echo e(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>" title="<?php echo __('edit'); ?>" aria-label="<?php echo e(__('edit') . ': ' . $item['part_name']); ?>">
                                        <i class="fas fa-edit" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" data-crm-action="delete-part" data-crm-id="<?php echo (int)$item['id']; ?>" title="<?php echo __('delete'); ?>" aria-label="<?php echo e(__('delete') . ': ' . $item['part_name']); ?>">
                                        <i class="fas fa-trash" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($order_items)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-state__mark" aria-hidden="true"></div>
                                    <p class="mb-0"><?php echo e(__('no_parts')); ?></p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('order_status'); ?></h5>
            </div>
            <div class="card-body">
                <form id="statusForm" data-current-shipping="<?php echo e($order['shipping_method'] ?? ''); ?>" data-order-type="<?php echo e($order['order_type'] ?? ''); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <?php if (!$show_shipping): ?>
                        <div class="text-white-75 small mb-2"><?php echo __('shipping_only_when_issued'); ?></div>
                    <?php endif; ?>
                    <?php if (!$show_invoice && hasPermission('admin_access')): ?>
                        <div class="text-white-75 small mb-2"><?php echo __('invoice_available_after_completed'); ?></div>
                    <?php endif; ?>

                    <div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('technician'); ?></label>
                            <select name="technician_id" class="form-select mb-2" <?php echo !hasPermission('admin_access') ? 'disabled' : ''; ?>>
                                <option value="">-- <?php echo __('edit'); ?> --</option>
                                <?php $techs = getActiveTechnicians(); foreach($techs as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $order['technician_id'] == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!hasPermission('admin_access')): ?>
                                <input type="hidden" name="technician_id" value="<?php echo $order['technician_id']; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span><?php echo __('status'); ?></span>
                                <span class="text-white-75 small">
                                    <span id="display_updated_at"><?php echo date('d.m.Y H:i', strtotime($status_date)); ?></span>
                                    <button type="button" class="btn btn-link p-0 ms-1 text-primary align-baseline" data-bs-toggle="modal" data-bs-target="#editOrderDatesModal" aria-label="<?php echo e(__('edit_order_dates')); ?>">
                                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                    </button>
                                </span>
                            </label>
                            <select name="status" class="form-select mb-2">
                                <?php foreach (getAllStatuses() as $status_option): ?>
                                    <option value="<?php echo e($status_option); ?>" <?php echo $status === $status_option ? 'selected' : ''; ?>>
                                        <?php echo e(getStatusLabel($status_option)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3 d-none" id="statusCancellationReasonWrap">
                            <label class="form-label"><?php echo __('cancellation_reason'); ?></label>
                            <textarea name="cancellation_reason" class="form-control" rows="3" placeholder="<?php echo __('cancellation_reason_placeholder'); ?>"><?php echo e($order['cancellation_reason'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3 d-none" id="statusShippingMethodWrap">
                            <label class="form-label"><?php echo __('shipping_method'); ?></label>
                            <select name="shipping_method" class="form-select">
                                <option value="" <?php echo empty($order['shipping_method']) ? 'selected' : ''; ?>><?php echo __('choose_option'); ?></option>
                                <option value="Self Pickup" <?php echo ($order['shipping_method'] ?? '') == 'Self Pickup' ? 'selected' : ''; ?>><?php echo __('self_pickup'); ?></option>
                                <option value="Zasilkovna" <?php echo ($order['shipping_method'] ?? '') == 'Zasilkovna' ? 'selected' : ''; ?>>Zasilkovna</option>
                                <option value="Ceska Posta" <?php echo ($order['shipping_method'] ?? '') == 'Ceska Posta' ? 'selected' : ''; ?>>Česká pošta</option>
                                <option value="PPL" <?php echo ($order['shipping_method'] ?? '') == 'PPL' ? 'selected' : ''; ?>>PPL</option>
                                <option value="DPD" <?php echo ($order['shipping_method'] ?? '') == 'DPD' ? 'selected' : ''; ?>>DPD</option>
                                <option value="GLS" <?php echo ($order['shipping_method'] ?? '') == 'GLS' ? 'selected' : ''; ?>>GLS</option>
                                <option value="Courier" <?php echo ($order['shipping_method'] ?? '') == 'Courier' ? 'selected' : ''; ?>><?php echo __('courier'); ?></option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('work_cost'); ?></label>
                            <div class="input-group">
                                <input type="number" name="final_cost" class="form-control" value="<?php echo e($order['final_cost'] ?? $order['estimated_cost']); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php if (hasPermission('admin_access')): ?>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('extra_expenses'); ?></label>
                            <div class="input-group">
                                <input type="number" name="extra_expenses" class="form-control" step="0.01" value="<?php echo e($order['extra_expenses'] ?? 0); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary w-100 mb-2"><?php echo __('update_status'); ?></button>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-print me-2" aria-hidden="true"></i> <?php echo __('print'); ?>
                            </button>
                            <ul class="dropdown-menu w-100 shadow">
                                <li><a class="dropdown-item py-2" href="#" data-crm-action="open-preview" data-preview-url="print_order.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Order #<?php echo (int)$order['id']; ?>"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                                <li><a class="dropdown-item py-2" href="#" data-crm-action="open-preview" data-preview-url="print_workshop.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Workshop #<?php echo (int)$order['id']; ?>"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                                <li><a class="dropdown-item py-2" href="#" data-crm-action="open-preview" data-preview-url="print_thermal.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Receipt #<?php echo (int)$order['id']; ?>"><i class="fas fa-receipt me-2 text-success"></i> <?php echo __('thermal_receipt'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($show_shipping): ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?php echo __('shipping'); ?></h5>
                <?php if($status === 'Issued'): ?>
                    <?php echo getStatusBadge('Issued'); ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="shippingForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('shipping_method'); ?></label>
                        <select name="shipping_method" class="form-select">
                            <option value="" <?php echo empty($order['shipping_method']) ? 'selected' : ''; ?>>-- <?php echo __('not_found'); ?> --</option>
                            <option value="Self Pickup" <?php echo $order['shipping_method'] == 'Self Pickup' ? 'selected' : ''; ?>><?php echo __('self_pickup'); ?></option>
                            <option value="Zasilkovna" <?php echo $order['shipping_method'] == 'Zasilkovna' ? 'selected' : ''; ?>>Zásilkovna</option>
                            <option value="Ceska Posta" <?php echo $order['shipping_method'] == 'Ceska Posta' ? 'selected' : ''; ?>>Česká pošta</option>
                            <option value="PPL" <?php echo $order['shipping_method'] == 'PPL' ? 'selected' : ''; ?>>PPL</option>
                            <option value="DPD" <?php echo $order['shipping_method'] == 'DPD' ? 'selected' : ''; ?>>DPD</option>
                            <option value="GLS" <?php echo $order['shipping_method'] == 'GLS' ? 'selected' : ''; ?>>GLS</option>
                            <option value="Courier" <?php echo $order['shipping_method'] == 'Courier' ? 'selected' : ''; ?>><?php echo __('courier'); ?></option>
                        </select>
                    </div>
                    <div id="shippingDetails" class="<?php echo in_array($order['shipping_method'], ['Self Pickup', 'Courier', '']) ? 'd-none' : ''; ?>">
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('shipping_tracking'); ?></label>
                            <input type="text" name="shipping_tracking" class="form-control" value="<?php echo htmlspecialchars($order['shipping_tracking'] ?? ''); ?>" placeholder="<?php echo __('tracking_placeholder'); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('shipping_date'); ?></label>
                        <input type="datetime-local" name="shipping_date" class="form-control" value="<?php echo $order['shipping_date'] ? date('Y-m-d\TH:i', strtotime($order['shipping_date'])) : ''; ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><?php echo __('save'); ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Express Invoice Block -->
        <?php if ($show_invoice): 
            // Fetch existing invoice for this order (first one)
            // The express form edits the order's regular invoice: never a credit note, and an active invoice
            // is preferred over a cancelled one.
            $stmt_inv = $pdo->prepare(
                "SELECT * FROM invoices
                 WHERE order_id = ? AND (invoice_type IS NULL OR invoice_type <> 'credit_note')
                 ORDER BY (status = 'cancelled') ASC, created_at DESC, id DESC
                 LIMIT 1"
            );
            $stmt_inv->execute([$id]);
            $existing_invoice = $stmt_inv->fetch();
            
            // Fetch invoice item if exists
            $invoice_item_name = __('repair_service') . ' #' . $order['id'];
            if ($existing_invoice) {
                $stmt_item = $pdo->prepare("SELECT item_name FROM invoice_items WHERE invoice_id = ? LIMIT 1");
                $stmt_item->execute([$existing_invoice['id']]);
                $inv_item = $stmt_item->fetch();
                if ($inv_item && !empty($inv_item['item_name'])) {
                    $invoice_item_name = $inv_item['item_name'];
                }
            }
        ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-invoice-dollar me-2 text-success"></i><?php echo __('invoice'); ?></h5>
                <?php if($existing_invoice): ?>
                    <?php echo getInvoiceStatusBadge($existing_invoice['status']); ?>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="expressInvoiceForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="invoice_id" value="<?php echo $existing_invoice['id'] ?? ''; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('invoice_number'); ?></label>
                        <input type="text" name="invoice_number" class="form-control" value="<?php echo e($existing_invoice['invoice_number'] ?? crmSuggestInvoiceNumber($pdo)); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('item_description'); ?></label>
                        <input type="text" name="item_name" class="form-control" value="<?php echo htmlspecialchars($invoice_item_name); ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" class="form-control" value="<?php echo $existing_invoice ? $existing_invoice['date_issue'] : date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" class="form-control" value="<?php echo $existing_invoice ? $existing_invoice['date_due'] : date('Y-m-d', strtotime('+14 days')); ?>" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label"><?php echo __('status'); ?></label>
                            <select name="status" class="form-select">
                                <option value="draft" <?php echo ($existing_invoice['status'] ?? '') == 'draft' ? 'selected' : ''; ?>><?php echo __('status_draft'); ?></option>
                                <option value="issued" <?php echo ($existing_invoice['status'] ?? 'issued') == 'issued' ? 'selected' : ''; ?>><?php echo __('status_invoice_issued'); ?></option>
                                <option value="paid" <?php echo ($existing_invoice['status'] ?? '') == 'paid' ? 'selected' : ''; ?>><?php echo __('status_paid'); ?></option>
                                <option value="overdue" <?php echo ($existing_invoice['status'] ?? '') == 'overdue' ? 'selected' : ''; ?>><?php echo __('status_overdue'); ?></option>
                                <option value="cancelled" <?php echo ($existing_invoice['status'] ?? '') == 'cancelled' ? 'selected' : ''; ?>><?php echo __('status_cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?php echo __('payment_method'); ?></label>
                            <select name="payment_method" class="form-select">
                                <option value="bank_transfer" <?php echo ($existing_invoice['payment_method'] ?? 'bank_transfer') == 'bank_transfer' ? 'selected' : ''; ?>><?php echo __('bank_transfer'); ?></option>
                                <option value="cash" <?php echo ($existing_invoice['payment_method'] ?? '') == 'cash' ? 'selected' : ''; ?>><?php echo __('cash'); ?></option>
                                <option value="card" <?php echo ($existing_invoice['payment_method'] ?? '') == 'card' ? 'selected' : ''; ?>><?php echo __('card'); ?></option>
                                <option value="cod" <?php echo ($existing_invoice['payment_method'] ?? '') == 'cod' ? 'selected' : ''; ?>><?php echo __('cod'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('total_to_pay'); ?></label>
                        <div class="input-group">
                            <input type="number" name="total_amount" class="form-control" step="0.01" value="<?php echo $existing_invoice ? $existing_invoice['total_amount'] : ($order['final_cost'] ?: $order['estimated_cost']); ?>" required>
                            <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-grow-1">
                            <i class="fas fa-<?php echo $existing_invoice ? 'save' : 'plus'; ?> me-2"></i>
                            <?php echo $existing_invoice ? __('save') : __('create_invoice'); ?>
                        </button>
                        <?php if($existing_invoice): ?>
                        <a href="#" data-crm-action="open-preview"
                           data-preview-url="print_invoice.php?id=<?php echo (int)$existing_invoice['id']; ?>"
                           data-preview-title="<?php echo e(__('invoice') . ' ' . $existing_invoice['invoice_number']); ?>"
                           class="btn btn-outline-secondary" title="<?php echo __('print'); ?>" aria-label="<?php echo e(__('print')); ?>">
                            <i class="fas fa-print" aria-hidden="true"></i>
                        </a>
                        <a href="#" data-crm-action="open-preview"
                           data-preview-url="print_invoice_thermal.php?id=<?php echo (int)$existing_invoice['id']; ?>"
                           data-preview-title="<?php echo e(__('thermal_receipt') . ' ' . $existing_invoice['invoice_number']); ?>"
                           class="btn btn-outline-secondary" title="<?php echo __('thermal_receipt'); ?>" aria-label="<?php echo e(__('thermal_receipt')); ?>">
                            <i class="fas fa-receipt" aria-hidden="true"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Add Part -->
<div class="modal fade" id="addPartModal" tabindex="-1" data-bs-focus="false" aria-labelledby="addPartModalTitle">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="addPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="addPartModalTitle"><?php echo __('add_part_to_order'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="btn-group w-100 mb-3" role="group" aria-label="<?php echo e(__('add_part_to_order')); ?>">
                        <input type="radio" class="btn-check" name="mode" id="partModeInventory" value="inventory" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="partModeInventory"><?php echo __('part_mode_inventory'); ?></label>
                        <input type="radio" class="btn-check" name="mode" id="partModeManual" value="manual" autocomplete="off">
                        <label class="btn btn-outline-primary" for="partModeManual"><?php echo __('part_mode_manual'); ?></label>
                    </div>
                    <div id="inventoryPartFields" class="mb-3">
                        <label class="form-label" for="addPartInventoryId"><?php echo __('select_part_from_warehouse'); ?></label>
                        <?php /* required is enforced in JS: Select2 hides the native select and HTML5 required then silently blocks submit */ ?>
                        <select name="inventory_id" id="addPartInventoryId" class="form-select">
                            <?php /* Options are loaded by search (api/search_inventory.php): the whole catalogue is not rendered. */ ?>
                            <option value=""><?php echo __('choose_option'); ?></option>
                        </select>
                    </div>
                    <div id="manualPartFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label" for="addPartName"><?php echo __('part_name'); ?></label>
                            <input type="text" name="part_name" id="addPartName" class="form-control" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addPartSource"><?php echo __('source'); ?></label>
                            <input type="text" name="source" id="addPartSource" class="form-control" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addPartPrice"><?php echo __('price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="price" id="addPartPrice" class="form-control" step="0.01" min="0">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="addPartQuantity"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" id="addPartQuantity" class="form-control" value="1" min="1" step="1" required>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('add'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Upload Media -->
<div class="modal fade" id="uploadMediaModal" tabindex="-1" aria-labelledby="uploadMediaModalTitle">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="uploadMediaForm" enctype="multipart/form-data">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="uploadMediaModalTitle"><?php echo __('upload_media'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('select_files'); ?></label>
                        <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*" required>
                    </div>
                    <div id="uploadProgress" class="progress d-none mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"><?php echo __('uploading'); ?>...</div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('upload'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Order Dates -->
<div class="modal fade" id="editOrderDatesModal" tabindex="-1" aria-labelledby="editOrderDatesModalTitle">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderDatesForm">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="editOrderDatesModalTitle"><?php echo __('edit_order_dates'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('created_at'); ?></label>
                        <input type="datetime-local" name="created_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($order['created_at'])); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('updated_at'); ?></label>
                        <input type="datetime-local" name="updated_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($status_date)); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Attachment Date -->
<div class="modal fade" id="editAttachmentDateModal" tabindex="-1" aria-labelledby="editAttachmentDateModalTitle">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editAttachmentDateForm">
                <input type="hidden" name="attachment_id" id="edit_attachment_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="editAttachmentDateModalTitle"><?php echo __('edit_upload_date'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('date_time'); ?></label>
                        <input type="datetime-local" name="created_at" id="edit_attachment_date" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Shipping Required Modal (Animated) -->
<div class="modal fade" id="shippingRequiredModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="shippingRequiredModalTitle">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="shippingRequiredModalTitle"><i class="fas fa-exclamation-triangle me-2 text-warning" aria-hidden="true"></i><?php echo __('shipping_required_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-4">
                    <i class="fas fa-shipping-fast fa-4x text-warning mb-3 animate-bounce" aria-hidden="true"></i>
                </div>
                <h5><?php echo __('shipping_required_title'); ?></h5>
                <p class="text-white-75 mb-0"><?php echo __('shipping_required_msg'); ?></p>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-primary px-4" data-crm-action="go-to-shipping">
                    <i class="fas fa-truck me-2" aria-hidden="true"></i><?php echo __('specify_shipping'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Status Confirm Modal (Animated) -->
<div class="modal fade" id="statusConfirmModal" tabindex="-1" aria-labelledby="statusConfirmModalTitle">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-card border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title" id="statusConfirmModalTitle"><i class="fas fa-check-circle me-2 text-success" aria-hidden="true"></i><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i class="fas fa-clipboard-check fa-3x text-success" aria-hidden="true"></i>
                </div>
                <p class="mb-0"><?php echo __('change_status_prompt'); ?></p>
                <h4 class="text-success mt-2" id="confirmStatusText"></h4>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-primary px-4" id="confirmStatusBtn">
                    <i class="fas fa-check me-2" aria-hidden="true"></i><?php echo __('confirm'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Full Edit Order Modal -->
<div class="modal fade" id="editOrderFullModal" tabindex="-1" data-bs-focus="false" aria-labelledby="editOrderFullModalTitle">
    <div class="modal-dialog modal-xl">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderFullForm">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="editOrderFullModalTitle"><?php echo __('edit_order_title'); ?> #<?php echo $order['id']; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('client'); ?></label>
                            <select name="customer_id" class="form-select select2-modal-customer">
                                <?php
                                $current_customer_name = trim(($order['last_name'] ?? '') . ' ' . ($order['first_name'] ?? ''));
                                $current_customer_company = trim($order['company'] ?? '');
                                if ($current_customer_company !== '') {
                                    $current_customer_name = $current_customer_company . ($current_customer_name !== '' ? ' (' . $current_customer_name . ')' : '');
                                }
                                $current_customer_label = $current_customer_name . (!empty($order['phone']) ? ' (' . $order['phone'] . ')' : '');
                                ?>
                                <option value="<?php echo (int)$order['customer_id']; ?>" selected>
                                    <?php echo htmlspecialchars($current_customer_label); ?>
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('brand'); ?></label>
                            <select name="device_brand" class="form-select select2-tags-modal">
                                <?php foreach(getDeviceBrands() as $brand): ?>
                                    <option value="<?php echo $brand; ?>" <?php echo ($brand == $order['device_brand']) ? 'selected' : ''; ?>><?php echo $brand; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('device_model'); ?></label>
                            <input type="text" name="device_model" class="form-control" value="<?php echo htmlspecialchars($order['device_model']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('device_type'); ?></label>
                            <select name="device_type" class="form-select">
                                <option value="Phone" <?php echo ($order['device_type'] == 'Phone') ? 'selected' : ''; ?>><?php echo __('phone_type'); ?></option>
                                <option value="Notebook" <?php echo ($order['device_type'] == 'Notebook') ? 'selected' : ''; ?>><?php echo __('notebook_type'); ?></option>
                                <option value="Tablet" <?php echo ($order['device_type'] == 'Tablet') ? 'selected' : ''; ?>><?php echo __('tablet_type'); ?></option>
                                <option value="Other" <?php echo ($order['device_type'] == 'Other') ? 'selected' : ''; ?>><?php echo __('other_type'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('technician'); ?></label>
                            <select name="technician_id" class="form-select" <?php echo !hasPermission('admin_access') ? 'disabled' : ''; ?>>
                                <option value=""><?php echo __('choose_option'); ?></option>
                                <?php 
                                foreach($techs as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" <?php if($order['technician_id']==$t['id']) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars($t['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('priority'); ?></label>
                            <select name="priority" class="form-select">
                                <option value="Normal" <?php echo ($order['priority'] == 'Normal') ? 'selected' : ''; ?>><?php echo __('normal'); ?></option>
                                <option value="High" <?php echo ($order['priority'] == 'High') ? 'selected' : ''; ?>><?php echo __('high'); ?> 🔥</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('warranty_type'); ?></label>
                            <select name="order_type" class="form-select">
                                <option value="Non-Warranty" <?php echo ($order['order_type'] == 'Non-Warranty') ? 'selected' : ''; ?>><?php echo __('paid_repair'); ?></option>
                                <option value="Warranty" <?php echo ($order['order_type'] == 'Warranty') ? 'selected' : ''; ?>><?php echo __('warranty_repair'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('serial'); ?></label>
                            <input type="text" name="serial_number" class="form-control" value="<?php echo htmlspecialchars($order['serial_number'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('serial_2'); ?></label>
                            <input type="text" name="serial_number_2" class="form-control" value="<?php echo htmlspecialchars($order['serial_number_2'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('pin'); ?></label>
                            <input type="text" name="pin_code" class="form-control" value="<?php echo htmlspecialchars($pinDecryptFailed ? '' : ($order['pin_code'] ?? '')); ?>" placeholder="<?php echo $pinDecryptFailed ? e(__('pin_reenter_placeholder')) : ''; ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('appearance'); ?></label>
                            <input type="text" name="appearance" class="form-control" value="<?php echo htmlspecialchars($order['appearance'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('problem'); ?></label>
                            <textarea name="problem_description" class="form-control" rows="3"><?php echo htmlspecialchars($order['problem_description']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('notes'); ?></label>
                            <textarea name="technician_notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['technician_notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('price_estimated'); ?></label>
                            <div class="input-group">
                                <input type="number" name="estimated_cost" class="form-control" step="0.01" value="<?php echo e($order['estimated_cost']); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('price_final'); ?></label>
                            <div class="input-group">
                                <input type="number" name="final_cost" class="form-control" step="0.01" value="<?php echo e($order['final_cost']); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php if (hasPermission('admin_access')): ?>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('extra_expenses'); ?></label>
                            <div class="input-group">
                                <input type="number" name="extra_expenses" class="form-control" step="0.01" value="<?php echo e($order['extra_expenses']); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save_changes'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit Part -->
<div class="modal fade" id="editPartModal" tabindex="-1" aria-labelledby="editPartModalTitle">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_item_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title" id="editPartModalTitle"><?php echo __('edit_part_title'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('part_name'); ?></label>
                        <input type="text" id="edit_item_name" class="form-control" readonly disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" id="edit_item_quantity" class="form-control" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('price_per_unit'); ?></label>
                        <div class="input-group">
                            <input type="number" name="price" id="edit_item_price" class="form-control" step="0.01" required>
                            <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/includes/partials/view_order_scripts.php'; ?>
<?php require_once 'includes/footer.php'; ?>
