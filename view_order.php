<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
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
$inventory = $pdo->query("SELECT id, part_name, quantity, sale_price FROM inventory ORDER BY part_name ASC LIMIT 500")->fetchAll();

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
?>

<?php
    $back_url = "javascript:history.back()";
    if (!empty($_GET['return'])) {
        $candidate_back_url = (string)$_GET['return'];
        $is_relative_url = !preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $candidate_back_url);
        $has_safe_chars = (bool)preg_match('/^[A-Za-z0-9_\/.\-]+(?:\?[A-Za-z0-9_=&%+.,:\-\/]*)?$/', $candidate_back_url);
        if ($is_relative_url && $has_safe_chars) {
            $back_url = $candidate_back_url;
        }
    }
?>

<div class="page-header">
    <div class="page-header__copy">
        <div class="page-kicker"><?php echo __('order'); ?></div>
        <h1>#<?php echo $order['id']; ?> · <?php echo htmlspecialchars($order['device_model']); ?></h1>
        <p class="page-subtitle"><?php echo __('created'); ?>: <?php echo $created_label; ?></p>
    </div>
    <div class="page-actions">
        <a href="<?php echo e($back_url); ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i>
            <span><?php echo __('back'); ?></span>
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editOrderFullModal">
            <i class="fas fa-edit"></i>
            <span><?php echo __('edit'); ?></span>
        </button>
        <?php if(hasPermission('admin_access')): ?>
        <button class="btn btn-outline-danger" onclick="deleteOrder(<?php echo $order['id']; ?>)">
            <i class="fas fa-trash"></i>
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
                            <strong><?php echo $order['order_type'] == 'Warranty' ? __('Warranty') : __('Non-Warranty'); ?></strong>
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
                            <code class="text-warning"><?php echo htmlspecialchars($order['pin_code'] ?: '---'); ?></code>
                        </div>
                        <h6 class="mt-3"><?php echo __('technician'); ?></h6>
                        <div class="alert alert-info bg-transparent border border-info py-2 mb-3 text-info">
                            <i class="fas fa-user-cog me-2"></i><strong><?php echo htmlspecialchars($order['tech_name'] ?: '---'); ?></strong>
                        </div>
                        <h6><?php echo __('priority'); ?></h6>
                        <?php if($order['priority'] == 'High'): ?>
                            <span class="badge bg-danger px-3 py-2 mt-1"><?php echo __('high'); ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary px-3 py-2 mt-1"><?php echo __('normal'); ?></span>
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
                                    <span class="badge bg-transparent border border-secondary text-white-75"><?php echo htmlspecialchars(getStatusLabel($log['old_status'])); ?></span>
                                    <i class="fas fa-arrow-right mx-1 text-white-75"></i>
                                    <span class="badge bg-primary text-white"><?php echo htmlspecialchars(getStatusLabel($log['new_status'])); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($who); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-white-75 small mb-4"><?php echo __('not_found'); ?></div>
                <?php endif; ?>

                <!-- Media Section -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0"><?php echo __('media_files'); ?></h6>
                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#uploadMediaModal">
                        <i class="fas fa-upload me-1"></i> <?php echo __('upload'); ?>
                    </button>
                </div>
                <div class="row g-2 mb-4">
                    <?php 
                    $stmt_files = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ? ORDER BY created_at DESC");
                    $stmt_files->execute([$id]);
                    $attachments = $stmt_files->fetchAll();
                    
                    if(empty($attachments)): ?>
                        <div class="col-12 text-white-75 small"><?php echo __('no_media_files'); ?></div>
                    <?php else:
                        foreach($attachments as $file): 
                            $is_video = strpos($file['file_type'], 'video') !== false;
                    ?>
                        <div class="col-6 col-md-3" id="media-item-<?php echo $file['id']; ?>">
                            <div class="card h-100 shadow-sm border position-relative">
                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 z-3 shadow-sm" 
                                        onclick="deleteMedia(<?php echo $file['id']; ?>)" style="padding: 2px 6px; font-size: 10px;">
                                    <i class="fas fa-times"></i>
                                </button>
                                <?php endif; ?>

                                <?php if($is_video): ?>
                                    <a href="<?php echo $file['file_path']; ?>" data-fancybox="gallery" data-type="video" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1 bg-dark d-flex align-items-center justify-content-center">
                                            <i class="fas fa-video fa-2x text-white"></i>
                                        </div>
                                    </a>
                                <?php else: ?>
                                    <a href="<?php echo $file['file_path']; ?>" data-fancybox="gallery" data-type="image" data-caption="<?php echo htmlspecialchars($file['file_name']); ?>">
                                        <div class="ratio ratio-1x1">
                                            <img src="<?php echo $file['file_path']; ?>" class="card-img-top object-fit-cover" alt="Photo">
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
                                        <a href="javascript:void(0)" class="ms-1 text-primary edit-attachment-date" 
                                           data-id="<?php echo $file['id']; ?>" 
                                           data-date="<?php echo date('Y-m-d\TH:i', strtotime($file['created_at'])); ?>"
                                           title="<?php echo __('edit'); ?>">
                                            <i class="fas fa-calendar-alt" style="font-size: 0.7rem;"></i>
                                        </a>
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
                
                <table class="table table-sm border align-middle">
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
                            <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                            <td class="text-center"><?php echo $item['quantity']; ?></td>
                            <td class="text-end"><?php echo formatMoney($item['price']); ?></td>
                            <td class="text-end fw-bold"><?php echo formatMoney($sum); ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="openEditPartModal(<?php echo htmlspecialchars(json_encode($item)); ?>)" title="<?php echo __('edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" onclick="deletePart(<?php echo $item['id']; ?>)" title="<?php echo __('delete'); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($order_items)): ?>
                        <tr><td colspan="5" class="text-center text-white-75 py-3"><?php echo __('no_parts'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('order_status'); ?></h5>
            </div>
            <div class="card-body">
                <form id="statusForm">
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
                            <select name="technician_id" class="form-select mb-2" <?php echo $_SESSION['role'] != 'admin' ? 'disabled' : ''; ?>>
                                <option value="">-- <?php echo __('edit'); ?> --</option>
                                <?php $techs = getActiveTechnicians(); foreach($techs as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $order['technician_id'] == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($_SESSION['role'] != 'admin'): ?>
                                <input type="hidden" name="technician_id" value="<?php echo $order['technician_id']; ?>">
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between align-items-center">
                                <span><?php echo __('status'); ?></span>
                                <span class="text-white-75 small">
                                    <span id="display_updated_at"><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?></span>
                                    <a href="javascript:void(0)" class="ms-1 text-primary" data-bs-toggle="modal" data-bs-target="#editOrderDatesModal" title="<?php echo __('edit'); ?>">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
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
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('extra_expenses'); ?></label>
                            <div class="input-group">
                                <input type="number" name="extra_expenses" class="form-control" step="0.01" value="<?php echo e($order['extra_expenses'] ?? 0); ?>">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-success w-100 mb-2"><?php echo __('update_status'); ?></button>
                        <div class="dropdown">
                            <button class="btn btn-outline-info w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-print me-2"></i> <?php echo __('print'); ?>
                            </button>
                            <ul class="dropdown-menu w-100 shadow">
                                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openUniversalPreview('print_order.php?id=<?php echo $order['id']; ?>', 'Order #<?php echo $order['id']; ?>')"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openUniversalPreview('print_workshop.php?id=<?php echo $order['id']; ?>', 'Workshop #<?php echo $order['id']; ?>')"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openUniversalPreview('print_thermal.php?id=<?php echo $order['id']; ?>', 'Receipt #<?php echo $order['id']; ?>')"><i class="fas fa-receipt me-2 text-success"></i> <?php echo __('thermal_receipt'); ?></a></li>
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
                    <span class="badge bg-success small"><?php echo getStatusLabel('Issued'); ?></span>
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
            $stmt_inv = $pdo->prepare("SELECT * FROM invoices WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
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
                    <span class="badge <?php echo $existing_invoice['status'] == 'paid' ? 'bg-success' : 'bg-warning text-white'; ?>">
                        <?php echo __($existing_invoice['status']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form id="expressInvoiceForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <input type="hidden" name="invoice_id" value="<?php echo $existing_invoice['id'] ?? ''; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('invoice_number'); ?></label>
                        <input type="text" name="invoice_number" class="form-control" value="<?php echo $existing_invoice['invoice_number'] ?? $order['id']; ?>" required>
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
                        <button type="submit" class="btn <?php echo $existing_invoice ? 'btn-primary' : 'btn-success'; ?> flex-grow-1">
                            <i class="fas fa-<?php echo $existing_invoice ? 'save' : 'plus'; ?> me-2"></i>
                            <?php echo $existing_invoice ? __('save') : __('create_invoice'); ?>
                        </button>
                        <?php if($existing_invoice): ?>
                        <a href="javascript:void(0)" onclick="openUniversalPreview('print_invoice.php?id=<?php echo $existing_invoice['id']; ?>', 'Invoice #<?php echo $existing_invoice['invoice_number']; ?>')" class="btn btn-outline-secondary" title="<?php echo __('print'); ?>">
                            <i class="fas fa-print"></i>
                        </a>
                        <a href="javascript:void(0)" onclick="openUniversalPreview('print_invoice_thermal.php?id=<?php echo $existing_invoice['id']; ?>', 'Receipt #<?php echo $existing_invoice['invoice_number']; ?>')" class="btn btn-outline-success" title="<?php echo __('thermal_receipt'); ?>">
                            <i class="fas fa-receipt"></i>
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
<div class="modal fade" id="addPartModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="addPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('add_part_to_order'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="mode" id="partModeInventory" value="inventory" autocomplete="off" checked>
                        <label class="btn btn-outline-primary" for="partModeInventory"><?php echo __('part_mode_inventory'); ?></label>
                        <input type="radio" class="btn-check" name="mode" id="partModeManual" value="manual" autocomplete="off">
                        <label class="btn btn-outline-primary" for="partModeManual"><?php echo __('part_mode_manual'); ?></label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('select_part_from_warehouse'); ?></label>
                        <select name="inventory_id" class="form-select" required>
                            <option value=""><?php echo __('choose_option'); ?></option>
                            <?php foreach($inventory as $item): ?>
                            <option value="<?php echo $item['id']; ?>">
                                <?php echo htmlspecialchars($item['part_name']); ?> (<?php echo __('in_stock'); ?>: <?php echo $item['quantity']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="manualPartFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('part_name'); ?></label>
                            <input type="text" name="part_name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('source'); ?></label>
                            <input type="text" name="source" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="price" class="form-control" step="0.01" min="0">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('quantity'); ?></label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1">
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
<div class="modal fade" id="uploadMediaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="uploadMediaForm" enctype="multipart/form-data">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('upload_media'); ?></h5>
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
<div class="modal fade" id="editOrderDatesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderDatesForm">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_order_dates'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('created_at'); ?></label>
                        <input type="datetime-local" name="created_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($order['created_at'])); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('updated_at'); ?></label>
                        <input type="datetime-local" name="updated_at" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($order['updated_at'])); ?>">
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
<div class="modal fade" id="editAttachmentDateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editAttachmentDateForm">
                <input type="hidden" name="attachment_id" id="edit_attachment_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_upload_date'); ?></h5>
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
<div class="modal fade" id="shippingRequiredModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card border-warning border-3 text-white">
            <div class="modal-header bg-warning bg-opacity-25 border-bottom-0">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2 text-warning"></i><?php echo __('shipping_required_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-4">
                    <i class="fas fa-shipping-fast fa-4x text-warning mb-3 animate-bounce"></i>
                </div>
                <h5><?php echo __('required_for_issue'); ?></h5>
                <p class="text-white-75 mb-0"><?php echo __('shipping_required_msg'); ?></p>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-warning px-4" onclick="goToShipping()">
                    <i class="fas fa-truck me-2"></i><?php echo __('specify_shipping'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Status Confirm Modal (Animated) -->
<div class="modal fade" id="statusConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content glass-card border-success border-2 text-white">
            <div class="modal-header bg-success bg-opacity-10 border-bottom-0">
                <h5 class="modal-title"><i class="fas fa-check-circle me-2 text-success"></i><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <i class="fas fa-clipboard-check fa-3x text-success"></i>
                </div>
                <p class="mb-0"><?php echo __('change_status_prompt'); ?></p>
                <h4 class="text-success mt-2" id="confirmStatusText"></h4>
            </div>
            <div class="modal-footer border-top-0 justify-content-center">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-success px-4" id="confirmStatusBtn">
                    <i class="fas fa-check me-2"></i><?php echo __('confirm'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Full Edit Order Modal -->
<div class="modal fade" id="editOrderFullModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editOrderFullForm">
                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_order_title'); ?> #<?php echo $order['id']; ?></h5>
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
                            <select name="technician_id" class="form-select" <?php echo $_SESSION['role'] != 'admin' ? 'disabled' : ''; ?>>
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
                            <input type="text" name="pin_code" class="form-control" value="<?php echo htmlspecialchars($order['pin_code'] ?? ''); ?>">
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
                        <?php if ($_SESSION['role'] == 'admin'): ?>
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
<div class="modal fade" id="editPartModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="editPartForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="id" id="edit_item_id">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><?php echo __('edit_part_title'); ?></h5>
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
