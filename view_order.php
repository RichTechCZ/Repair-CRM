<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) die(__('order_id_missing'));

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       LEFT JOIN technicians t ON o.technician_id = t.id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die(__('order_not_found'));

// Access Control for technicians
if ($_SESSION['role'] == 'technician' && !hasPermission('view_all_orders') && $order['technician_id'] != $_SESSION['tech_id']) {
    die(__('no_edit_permission'));
}

// Fetch parts linked to this order
$stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$order_items = $stmt->fetchAll();

// Fetch all available parts for the dropdown (limit 500 max to prevent HTML crash)
$inventory = $pdo->query("SELECT id, part_name, quantity, sale_price FROM inventory ORDER BY part_name ASC LIMIT 500")->fetchAll();

// Fetch all customers for edit modal (limit 500 max to prevent HTML crash)
$customers_list = $pdo->query("SELECT id, first_name, last_name, phone FROM customers ORDER BY last_name ASC LIMIT 500")->fetchAll();

// Fetch active technicians for edit modal
$techs = getActiveTechnicians();

$status = $order['status'] ?? 'New';
$next_status_map = [
    'New' => 'In Progress',
    'Pending Approval' => 'In Progress',
    'Waiting for Parts' => 'In Progress',
    'In Progress' => 'Completed',
    'Completed' => 'Collected'
];
$next_status = $next_status_map[$status] ?? null;
$next_label_map = [
    'In Progress' => __('move_to_in_progress'),
    'Completed' => __('move_to_completed'),
    'Collected' => __('move_to_collected')
];
$next_label = $next_status ? ($next_label_map[$next_status] ?? __('next_step')) : '';
$show_shipping = in_array($status, ['Completed', 'Collected'], true);
$show_invoice = hasPermission('admin_access')
    && in_array($status, ['Completed', 'Collected'], true)
    && (($order['final_cost'] ?? 0) > 0 || ($order['estimated_cost'] ?? 0) > 0);

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

<div class="row">
    <div class="col-md-8">
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center">
                    <?php 
                        $back_url = $_GET['return'] ?? "javascript:history.back()";
                    ?>
                    <a href="<?php echo $back_url; ?>" class="btn btn-outline-secondary btn-sm me-2" title="<?php echo __('back'); ?>">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <button class="btn btn-sm btn-primary me-2" data-bs-toggle="modal" data-bs-target="#editOrderFullModal">
                        <i class="fas fa-edit me-1"></i> <?php echo __('edit'); ?>
                    </button>
                    <?php if(hasPermission('admin_access')): ?>
                    <button class="btn btn-sm btn-outline-danger me-3" onclick="deleteOrder(<?php echo $order['id']; ?>)">
                        <i class="fas fa-trash me-1"></i> <?php echo __('delete'); ?>
                    </button>
                    <?php endif; ?>
                    <h5 class="mb-0">
                        <?php echo __('order'); ?> #<?php echo $order['id']; ?> - <?php echo htmlspecialchars($order['device_model']); ?>
                        <span class="text-white-75 fw-normal ms-2" style="font-size: 0.9rem;">
                            (<?php echo __('created'); ?>: <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?>)
                        </span>
                    </h5>
                </div>
                <?php echo getStatusBadge($order['status']); ?>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6><?php echo __('client'); ?></h6>
                        <p class="mb-1"><strong><?php echo htmlspecialchars($order['first_name'].' '.$order['last_name']); ?></strong></p>
                        <p class="text-white-75"><i class="fas fa-phone me-2 text-success"></i><?php echo htmlspecialchars($order['phone']); ?></p>
                    </div>
                    <div class="col-md-6 text-md-end">
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
                </div>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <h6><?php echo __('pin'); ?></h6>
                        <div class="alert alert-warning bg-transparent border border-warning py-2 mb-0">
                            <code class="text-warning"><?php echo htmlspecialchars($order['pin_code'] ?: '---'); ?></code>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6><?php echo __('technician'); ?></h6>
                        <div class="alert alert-info bg-transparent border border-info py-2 mb-0 text-info">
                            <i class="fas fa-user-cog me-2"></i><strong><?php echo htmlspecialchars($order['tech_name'] ?: '---'); ?></strong>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
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
                                    <span class="badge bg-transparent border border-secondary text-white-75"><?php echo htmlspecialchars($log['old_status']); ?></span>
                                    <i class="fas fa-arrow-right mx-1 text-white-75"></i>
                                    <span class="badge bg-primary text-white"><?php echo htmlspecialchars($log['new_status']); ?></span>
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
                <h5 class="mb-0"><?php echo __('actions'); ?></h5>
            </div>
            <div class="card-body">
                <form id="statusForm">
                    <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                    <div class="d-grid gap-2 mb-2">
                        <?php if ($next_status): ?>
                            <button type="button" class="btn btn-success" id="nextStatusBtn" data-next-status="<?php echo $next_status; ?>">
                                <?php echo $next_label ?: __('next_step'); ?>
                            </button>
                        <?php else: ?>
                            <div class="text-white-75 small"><?php echo __('no_next_step'); ?></div>
                        <?php endif; ?>
                        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#actionsAdvanced" aria-expanded="false">
                            <?php echo __('more_actions'); ?>
                        </button>
                    </div>

                    <?php if (!$show_shipping): ?>
                        <div class="text-white-75 small mb-2"><?php echo __('shipping_available_after_completed'); ?></div>
                    <?php endif; ?>
                    <?php if (!$show_invoice && hasPermission('admin_access')): ?>
                        <div class="text-white-75 small mb-2"><?php echo __('invoice_available_after_completed'); ?></div>
                    <?php endif; ?>

                    <div class="collapse" id="actionsAdvanced">
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
                                <?php if (($order['status'] ?? '') === 'Collected'): ?>
                                    <option value="Collected" selected><?php echo __('collected'); ?></option>
                                <?php else: ?>
                                    <option value="New" <?php if($order['status']=='New') echo 'selected'; ?>><?php echo __('new'); ?></option>
                                    <option value="Pending Approval" <?php if($order['status']=='Pending Approval') echo 'selected'; ?>><?php echo __('pending_approval'); ?></option>
                                    <option value="In Progress" <?php if($order['status']=='In Progress') echo 'selected'; ?>><?php echo __('in_progress'); ?></option>
                                    <option value="Waiting for Parts" <?php if($order['status']=='Waiting for Parts') echo 'selected'; ?>><?php echo __('waiting_parts'); ?></option>
                                    <option value="Completed" <?php if($order['status']=='Completed') echo 'selected'; ?>><?php echo __('completed'); ?></option>
                                    <option value="Collected" <?php if($order['status']=='Collected') echo 'selected'; ?>><?php echo __('collected'); ?></option>
                                    <option value="Cancelled" <?php if($order['status']=='Cancelled') echo 'selected'; ?>><?php echo __('cancelled'); ?></option>
                                <?php endif; ?>
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
                <?php if($order['status'] === 'Collected'): ?>
                    <span class="badge bg-success small"><?php echo __('collected'); ?></span>
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
                                <option value="issued" <?php echo ($existing_invoice['status'] ?? 'issued') == 'issued' ? 'selected' : ''; ?>><?php echo __('status_issued'); ?></option>
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

<script>
$(document).ready(function() {
    // Express Invoice Form
    $('#expressInvoiceForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __("saving"); ?>...');
        
        $.post('api/create_express_invoice.php', $(this).serialize(), function(res) {
            if(res.success) {
                // Just reload to show updated invoice status and amounts
                location.reload();
            } else {
                btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
                showAlert('<?php echo __("error"); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-plus me-2"></i><?php echo __("create_invoice"); ?>');
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __("error"); ?>';
            showAlert(msg);
        });
    });

    $('#statusForm').on('submit', function(e) {
        e.preventDefault();
        const form = $(this);
        const status = form.find('select[name="status"]').val();
        const shippingMethod = $('select[name="shipping_method"]').val();
        
        if (status === 'Collected' && (!shippingMethod || shippingMethod === '')) {
            showShippingRequiredModal();
            return false;
        }
        
        if (status === 'Collected') {
            const shippingForm = $('#shippingForm');
            $.post('api/update_shipping.php', shippingForm.serialize(), function(res) {
                if (res.success) {
                    showStatusConfirmModal(form);
                } else {
                    showAlert('<?php echo __('error'); ?>: ' + (res.message || '<?php echo __('error_shipping_update'); ?>'));
                }
            }).fail(function() {
                showAlert('<?php echo __('error'); ?>: <?php echo __('error_shipping_connect'); ?>');
            });
        } else {
            showStatusConfirmModal(form);
        }
    });

    $('#nextStatusBtn').on('click', function() {
        const nextStatus = $(this).data('next-status');
        if (!nextStatus) return;
        const form = $('#statusForm');
        form.find('select[name="status"]').val(nextStatus);
        form.submit();
    });
    
    // ... existing scripts ...
    $('#editOrderDatesForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_dates.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('.edit-attachment-date').on('click', function() {
        $('#edit_attachment_id').val($(this).data('id'));
        $('#edit_attachment_date').val($(this).data('date'));
        $('#editAttachmentDateModal').modal('show');
    });

    $('#editAttachmentDateForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_attachment_date.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    // Initialize Fancybox 5
    if (typeof Fancybox !== 'undefined') {
        Fancybox.bind("[data-fancybox]", {
            dragToClose: false,
            Image: {
                zoom: true,
            },
        });
    }

    // Initialize Select2
    $('.select2-customer').select2({
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        allowClear: true,
        width: '100%'
    });

    $('select[name="inventory_id"]').select2({
        dropdownParent: $('#addPartModal'),
        placeholder: "<?php echo __('search_part_placeholder'); ?>",
        width: '100%'
    });

    $('#shippingForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_shipping.php', $(this).serialize(), function(res) {
            if(res.success) {
                showAlert('<?php echo __('shipping_updated'); ?>');
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('select[name="shipping_method"]').on('change', function() {
        const method = $(this).val();
        if (['Zasilkovna', 'Ceska Posta', 'PPL', 'DPD', 'GLS'].includes(method)) {
            $('#shippingDetails').removeClass('d-none');
        } else {
            $('#shippingDetails').addClass('d-none');
        }
    });

    $('#addPartForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/add_order_item.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('#editPartForm').on('submit', function(e) {
        e.preventDefault();
        $.post('api/update_order_item.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });

    $('#uploadMediaForm').on('submit', function(e) {
        e.preventDefault();
        var formData = new FormData(this);
        $('#uploadProgress').removeClass('d-none');
        
        $.ajax({
            url: 'api/upload_media.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                $('#uploadProgress').addClass('d-none');
                if (res.success) {
                    showAlert('<?php echo __('files_uploaded'); ?>' + res.count);
                    location.reload();
                } else {
                    showAlert('<?php echo __('error'); ?>: ' + res.message);
                }
            },
            error: function() {
                $('#uploadProgress').addClass('d-none');
                showAlert('<?php echo __('upload_error'); ?>');
            }
        });
    });

    // Full Edit Form AJAX
    $('#editOrderFullForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const oldHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');
        
        $.post('api/update_order_full.php', $(this).serialize(), function(res) {
            if(res.success) {
                location.reload();
            } else {
                btn.prop('disabled', false).html(oldHtml);
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html(oldHtml);
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '<?php echo __('network_error'); ?>';
            showAlert(msg);
        });
    });

    // Initialize Select2 in modal
    $('.select2-modal').select2({
        dropdownParent: $('#editOrderFullModal'),
        width: '100%'
    });

    $('.select2-tags-modal').select2({
        dropdownParent: $('#editOrderFullModal'),
        tags: true,
        width: '100%'
    });
});

function deletePart(id) {
    showConfirm('<?php echo __('confirm_delete_part'); ?>', function() {
        $.post('api/delete_order_item.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

function openEditPartModal(item) {
    $('#edit_item_id').val(item.id);
    $('#edit_item_name').val(item.part_name);
    $('#edit_item_quantity').val(item.quantity);
    $('#edit_item_price').val(item.price);
    
    var editModal = new bootstrap.Modal(document.getElementById('editPartModal'));
    editModal.show();
}

function testTechTG(id) {
    if (!id) return;
    $.post('api/test_tech_tg.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
        if (res.success) {
            showAlert('<?php echo __('test_msg_sent'); ?>');
        } else {
            showAlert('<?php echo __('error'); ?>: ' + res.message);
        }
    });
}

function deleteMedia(id) {
    if (typeof showConfirm !== 'function') {
        if (confirm('<?php echo __('confirm_delete_file'); ?>')) {
            $.post('api/delete_media.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
                if (res.success) $('#media-item-' + id).fadeOut();
                else alert('Error: ' + res.message);
            });
        }
        return;
    }
    showConfirm('<?php echo __('confirm_delete_file'); ?>', function() {
        $.post('api/delete_media.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                $('#media-item-' + id).fadeOut();
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

// Show animated modal when shipping method is required for Collected status
function showShippingRequiredModal() {
    const modal = $('#shippingRequiredModal');
    modal.modal('show');
    
    // Add shake animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-shake');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-shake');
        }, 600);
    }, 100);
}

// Show status confirmation modal with animation
function showStatusConfirmModal(form) {
    const modal = $('#statusConfirmModal');
    const status = form.find('select[name="status"]').val();
    const statusLabels = {
        'New': '<?php echo __("new"); ?>',
        'Pending Approval': '<?php echo __("pending_approval"); ?>',
        'In Progress': '<?php echo __("in_progress"); ?>',
        'Waiting for Parts': '<?php echo __("waiting_parts"); ?>',
        'Completed': '<?php echo __("completed"); ?>',
        'Collected': '<?php echo __("collected"); ?>',
        'Cancelled': '<?php echo __("cancelled"); ?>'
    };
    
    $('#confirmStatusText').text(statusLabels[status] || status);
    modal.modal('show');
    
    // Add pulse animation
    setTimeout(function() {
        modal.find('.modal-content').addClass('animate-pulse');
        setTimeout(function() {
            modal.find('.modal-content').removeClass('animate-pulse');
        }, 500);
    }, 100);
    
    // Handle confirm button
    $('#confirmStatusBtn').off('click').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ...');

        $.post('api/update_order_status.php', form.serialize(), function(raw) {
            let res = null;
            try {
                res = (typeof raw === 'string') ? JSON.parse(raw) : raw;
            } catch (e) {
                res = null;
            }

            if (res && res.success) {
                modal.modal('hide');
                location.reload();
                return;
            }

            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            if (res && res.message) {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            } else if (typeof raw === 'string' && raw.trim() !== '') {
                showAlert('<?php echo __('error'); ?>: ' + raw.trim());
            } else {
                showAlert('<?php echo __('error'); ?>');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false).html('<?php echo __("confirm"); ?>');
            const text = (xhr && xhr.responseText) ? xhr.responseText : '';
            showAlert('<?php echo __('error'); ?>' + (text ? ': ' + text : ''));
        });
    });
}

// Go to shipping section
function goToShipping() {
    $('#shippingRequiredModal').modal('hide');
    // Scroll to shipping form and highlight it
    $('html, body').animate({
        scrollTop: $('#shippingForm').offset().top - 100
    }, 500);
    
    // Highlight shipping method dropdown
    $('select[name="shipping_method"]').addClass('border-danger border-2');
    setTimeout(function() {
        $('select[name="shipping_method"]').focus();
    }, 600);
}

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                window.location.href = 'orders.php';
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}
</script>

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
                <h5><?php echo __('before_status_collected'); ?></h5>
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
                            <select name="customer_id" class="form-select select2-modal">
                                <?php foreach($customers_list as $cl): ?>
                                    <option value="<?php echo $cl['id']; ?>" <?php echo ($cl['id'] == $order['customer_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cl['last_name'] . ' ' . $cl['first_name'] . ' (' . $cl['phone'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
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


<?php require_once 'includes/footer.php'; ?>

