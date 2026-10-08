<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// ── Pagination ────────────────────────────────────────────────────────────────
$limit  = 13;
$page   = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $limit;

// FIX #7: Whitelist filter values to prevent unexpected SQL behavior
$allowed_statuses = array_merge(getAllStatuses(), array_keys(getLegacyStatusMap()));
$filter_status    = in_array($_GET['filter'] ?? '', $allowed_statuses, true) ? $_GET['filter'] : null;
$dashboard_status_groups = getDashboardStatusGroups();
$canonical_filter_status = canonicalOrderStatus($filter_status ?? '');

$orders       = [];
$total_orders = 0;
$imei_counts  = [];
$order_parts  = [];

if (isset($pdo)) {
    try {
        // Shared search path with Dashboard topbar: same scoring, same
        // optional-index fallback, same technician scoping.
        $orders_technician_id = null;
        if (($_SESSION['role'] ?? '') === 'technician') {
            $orders_technician_id = (int)($_SESSION['tech_id'] ?? 0);
        }
        $search_result = searchOrdersList(
            $pdo,
            (string)($_GET['search'] ?? ''),
            $orders_technician_id,
            $filter_status,
            $limit,
            $offset,
            true
        );
        $orders = $search_result['orders'];
        $total_orders = $search_result['total'];

        // FIX #4: Pre-load media flags in one query instead of N+1 in loop
        $has_media_ids = [];
        if (!empty($orders)) {
            $order_ids    = array_column($orders, 'id');
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $m_stmt       = $pdo->prepare(
                "SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id"
            );
            $m_stmt->execute($order_ids);
            $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        // Pre-load IMEI/Serial duplicate counts and parts for the page (no N+1)
        if (!empty($orders)) {
            $serials = [];
            foreach ($orders as $ord) {
                if (!empty(trim((string)$ord['serial_number'])))   $serials[] = trim((string)$ord['serial_number']);
                if (!empty(trim((string)$ord['serial_number_2']))) $serials[] = trim((string)$ord['serial_number_2']);
            }
            $serials = array_values(array_unique(array_filter($serials)));
            if (!empty($serials)) {
                $sn_ph = implode(',', array_fill(0, count($serials), '?'));
                $dup_stmt = $pdo->prepare(
                    "SELECT sn, COUNT(*) AS cnt FROM (
                        SELECT serial_number AS sn FROM orders
                            WHERE serial_number IN ($sn_ph) AND serial_number <> ''
                        UNION ALL
                        SELECT serial_number_2 AS sn FROM orders
                            WHERE serial_number_2 IN ($sn_ph) AND serial_number_2 <> ''
                    ) AS combined GROUP BY sn HAVING cnt > 1"
                );
                $dup_stmt->execute(array_merge($serials, $serials));
                foreach ($dup_stmt->fetchAll() as $dup_row) {
                    $imei_counts[$dup_row['sn']] = (int)$dup_row['cnt'];
                }
            }

            // Financial breakdown (parts + extra expenses) is admin-only.
            // Covers users-table admin and technicians with admin_access
            // (e.g. Shaydovskyy Andriy).
            if (hasPermission('admin_access')) {
                $order_ids = array_map('intval', array_column($orders, 'id'));
                $parts_ph = implode(',', array_fill(0, count($order_ids), '?'));
                $parts_stmt = $pdo->prepare(
                    "SELECT oi.order_id,
                            oi.quantity,
                            oi.price,
                            COALESCE(NULLIF(TRIM(oi.part_name), ''), NULLIF(TRIM(i.part_name), ''), '—') AS part_label
                     FROM order_items oi
                     LEFT JOIN inventory i ON i.id = oi.inventory_id
                     WHERE oi.order_id IN ($parts_ph)
                     ORDER BY oi.id ASC"
                );
                $parts_stmt->execute($order_ids);
                foreach ($parts_stmt->fetchAll(PDO::FETCH_ASSOC) as $part_row) {
                    $oid = (int)$part_row['order_id'];
                    $qty = max(1, (int)($part_row['quantity'] ?? 1));
                    $unit = (float)($part_row['price'] ?? 0);
                    $line_total = $qty * $unit;
                    if (!isset($order_parts[$oid])) {
                        $order_parts[$oid] = ['total' => 0.0, 'lines' => []];
                    }
                    $order_parts[$oid]['total'] += $line_total;
                    $order_parts[$oid]['lines'][] = sprintf(
                        '%s ×%d — %s',
                        (string)$part_row['part_label'],
                        $qty,
                        formatMoney($line_total)
                    );
                }
            }
        }

    } catch (PDOException $e) {
        error_log('orders.php query error: ' . $e->getMessage());
    }
}

$total_pages = $total_orders > 0 ? (int)ceil($total_orders / $limit) : 1;

$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

$sla_new_hours = (int)get_setting('sla_new_hours', 24);
$sla_progress_hours = (int)get_setting('sla_progress_hours', 72);
$now_ts = time();

// FIX #9 (stats): single query instead of 4 separate queries
$s_new = $s_pending = $s_progress = $s_ready = 0;
if (isset($pdo)) {
    try {
        $dashboard_technician_id = null;
        if (($_SESSION['role'] ?? '') === 'technician') {
            $dashboard_technician_id = (int)($_SESSION['tech_id'] ?? 0);
        }
        $s_new = countOrdersByStatusGroup($dashboard_status_groups['new'], $dashboard_technician_id);
        $s_pending = countOrdersByStatusGroup($dashboard_status_groups['pending'], $dashboard_technician_id);
        $s_progress = countOrdersByStatusGroup($dashboard_status_groups['progress'], $dashboard_technician_id);
        $s_ready = countOrdersByStatusGroup($dashboard_status_groups['ready'], $dashboard_technician_id);
    } catch (PDOException $e) {
        error_log('orders.php stats error: ' . $e->getMessage());
    }
}

// FIX #5: Load technicians once (used in both New Order and Quick Edit modals)
$techs_list = [];
if (isset($pdo)) {
    try {
        $techs_list = $pdo->query(
            'SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();
    } catch (PDOException $e) {}
}

$order_form_error = trim((string)($_SESSION['order_form_error'] ?? ''));
unset($_SESSION['order_form_error']);
?>

<section class="workspace-overview workspace-overview--orders ui-ready" aria-labelledby="orders-overview-title">
    <div class="workspace-overview__head">
        <div class="workspace-overview__copy">
            <h1 id="orders-overview-title"><?php echo __('orders'); ?></h1>
            <p class="workspace-overview__total">
                <?php echo __('all_orders'); ?>: <strong class="financial-number"><?php echo $total_orders; ?></strong>
            </p>
        </div>
        <form action="<?php echo e($search_action); ?>" method="GET" class="workspace-overview__search" role="search">
            <label for="ordersSearch" class="visually-hidden"><?php echo e(__('search_placeholder')); ?></label>
            <div class="search-shell">
                <span class="search-shell__icon" aria-hidden="true"></span>
                <input id="ordersSearch" type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
                <button class="btn btn-primary btn-sm px-3" type="submit">Go</button>
            </div>
        </form>
        <div class="page-actions">
            <?php if(!empty($_GET['search'])): ?>
                <a href="orders.php" class="btn btn-outline-secondary"><?php echo __('cancel'); ?></a>
            <?php endif; ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newOrderModal">
                <i class="fas fa-plus" aria-hidden="true"></i>
                <span><?php echo __('new_order'); ?></span>
            </button>
        </div>
    </div>

    <div class="workspace-overview__metrics workspace-overview__metrics--four" aria-label="<?php echo e(__('status')); ?>">
        <a href="?filter=Accepted" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Accepted' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('new_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $s_new; ?></strong>
                <?php echo getStatusBadge('Accepted'); ?>
            </span>
        </a>
        <a href="?filter=Approval" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Approval' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('pending_approval_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $s_pending; ?></strong>
                <?php echo getStatusBadge('Approval'); ?>
            </span>
        </a>
        <a href="?filter=In%20Repair" class="workspace-overview__metric <?php echo $canonical_filter_status == 'In Repair' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('in_progress_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $s_progress; ?></strong>
                <?php echo getStatusBadge('In Repair'); ?>
            </span>
        </a>
        <a href="?filter=Ready" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Ready' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('completed_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $s_ready; ?></strong>
                <?php echo getStatusBadge('Ready'); ?>
            </span>
        </a>
    </div>
</section>

<?php if ($order_form_error !== ''): ?>
<div class="alert alert-danger order-created-feedback" role="alert">
    <?php echo e($order_form_error); ?>
</div>
<?php endif; ?>

<?php $created_order_id = filter_input(INPUT_GET, 'created_order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); ?>
<?php if ($created_order_id): ?>
<div class="alert alert-success order-created-feedback d-flex flex-wrap align-items-center justify-content-between gap-3" role="status">
    <div>
        <strong><?php echo e(sprintf(__('order_created'), $created_order_id)); ?></strong>
        <div class="small text-white-75 mt-1"><?php echo e(__('order_created_hint')); ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="view_order.php?id=<?php echo (int)$created_order_id; ?>" class="btn btn-outline-secondary btn-sm"><?php echo __('open_btn'); ?></a>
        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal"><?php echo __('new_order'); ?></button>
    </div>
</div>
<?php endif; ?>

<?php if($filter_status): ?>
    <?php $status_label = getStatusLabel($filter_status); ?>
    <div class="mb-4">
        <span class="summary-chip"><?php echo e(__('status')); ?>: <?php echo e($status_label); ?></span>
    </div>
<?php endif; ?>

<div class="card glass-card shadow-sm ui-ready">
    <div class="card-body p-0">
        <div class="table-responsive table-scroll-touch orders-table-wrap">
            <table class="table table-hover align-middle mb-0 table-mobile-cards">
                <thead class="bg-transparent sticky-top" style="z-index: 10;">
                    <tr>
                        <th class="ps-4">ID / <?php echo __('created'); ?></th>
                        <th><?php echo __('client'); ?></th>
                        <th><?php echo __('device_model'); ?></th>
                        <th><?php echo __('problem'); ?></th>
                        <th><?php echo __('status'); ?></th>
                        <th><?php echo __('amount'); ?></th>
                        <th class="text-end pe-4"><?php echo __('action'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-white-75">
                                <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>
                                <?php echo __('not_found'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                        <?php
                            // FIX #4: use pre-loaded array instead of per-row query
                            $has_media   = isset($has_media_ids[$order['id']]);
                            $device_icon = getDeviceIcon($order['device_type']);
                            $client_phone = $order['phone'] ?? '';
                            $phone_clean  = preg_replace('/[^0-9+]/', '', $client_phone);
                        ?>
                        <tr <?php echo (($order['priority'] ?? '') === 'High') ? 'class="priority-high-row"' : ''; ?>>
                            <td class="ps-4" data-label="ID">
                                <a href="view_order.php?id=<?php echo (int)$order['id']; ?>" class="fw-bold text-decoration-none mobile-order-link">#<?php echo (int)$order['id']; ?></a>
                                <?php if($has_media): ?>
                                    <i class="fas fa-camera text-info ms-1" title="<?php echo __('media_files'); ?>"></i>
                                <?php endif; ?>
                                <div class="small text-white-75"><?php echo date('d.m.Y', strtotime($order['created_at'])); ?></div>
                                <?php if (($order['priority'] ?? '') === 'High'): ?>
                                    <div class="priority-chip priority-chip-animated">
                                        <i class="fas fa-bolt"></i>
                                        <span><?php echo __('high'); ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo e(__('client')); ?>">
                                <div><?php echo e($order['first_name'] . ' ' . $order['last_name']); ?></div>
                                <?php if($client_phone): ?>
                                <div class="phone-qr-trigger small text-white-75"
                                     data-phone="<?php echo e($phone_clean); ?>"
                                     role="button"
                                     tabindex="0"
                                     aria-expanded="false"
                                     aria-controls="phoneQrPopover"
                                     aria-haspopup="dialog"
                                     aria-label="<?php echo e($client_phone . ' — ' . __('phone_show_qr')); ?>">
                                    <i class="fas fa-phone text-success" aria-hidden="true"></i>
                                    <span><?php echo e($client_phone); ?></span>
                                    <i class="fas fa-qrcode phone-qr-trigger__hint" aria-hidden="true"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo e(__('device_model')); ?>">
                                <div class="fw-medium text-primary"><?php echo $device_icon; ?> <?php echo htmlspecialchars($order['device_brand']); ?></div>
                                <div class="small text-white-75"><?php echo htmlspecialchars($order['device_model']); ?></div>
                                <?php if(!empty($order['serial_number'])): ?>
                                    <div class="small text-white-75">
                                        <i class="fas fa-barcode me-1"></i><?php echo __('sn1'); ?>: <?php echo htmlspecialchars($order['serial_number']); ?>
                                        <?php $sn1_count = $imei_counts[trim($order['serial_number'])] ?? 0; if ($sn1_count > 1): ?>
                                            <span class="imei-dup-badge" data-sn="<?php echo htmlspecialchars(trim($order['serial_number']), ENT_QUOTES); ?>" title="<?php echo $sn1_count; ?> заявок с таким номером"><?php echo $sn1_count; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if(!empty($order['serial_number_2'])): ?>
                                    <div class="small text-white-75">
                                        <i class="fas fa-barcode me-1"></i><?php echo __('sn2'); ?>: <?php echo htmlspecialchars($order['serial_number_2']); ?>
                                        <?php $sn2_count = $imei_counts[trim($order['serial_number_2'])] ?? 0; if ($sn2_count > 1): ?>
                                            <span class="imei-dup-badge" data-sn="<?php echo htmlspecialchars(trim($order['serial_number_2']), ENT_QUOTES); ?>" title="<?php echo $sn2_count; ?> заявок с таким номером"><?php echo $sn2_count; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo e(__('problem')); ?>">
                                <div class="small problem-snippet" title="<?php echo htmlspecialchars($order['problem_description']); ?>">
                                    <?php echo htmlspecialchars($order['problem_description']); ?>
                                </div>
                            </td>
                            <td data-label="<?php echo e(__('status')); ?>">
                                <?php echo getStatusBadge($order['status']); ?>
                                <?php if(!empty($order['shipping_method'])): ?>
                                    <div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i><?php echo htmlspecialchars($order['shipping_method']); ?></div>
                                <?php endif; ?>
                                <div class="small text-white-75 mt-1">
                                    <i class="far fa-clock me-1"></i><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?>
                                </div>
                                <?php if(!empty($order['tech_name'])): ?>
                                <div class="small text-white-75 mt-1">
                                    <i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($order['tech_name']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="order-amount-cell" data-label="<?php echo e(__('amount')); ?>">
                                <?php
                                    $repair_total = (float)($order['final_cost'] ?: $order['estimated_cost'] ?: 0);
                                    $show_amount_breakdown = hasPermission('admin_access');
                                ?>
                                <div class="order-amount-cell__total financial-number fw-bold text-white" title="<?php echo e(__('amount')); ?>">
                                    <?php echo formatMoney($repair_total); ?>
                                </div>
                                <?php if ($show_amount_breakdown):
                                    $parts_meta = $order_parts[(int)$order['id']] ?? ['total' => 0.0, 'lines' => []];
                                    $parts_total = (float)$parts_meta['total'];
                                    $parts_tip = implode("\n", $parts_meta['lines']);
                                    $extra_exp = (float)($order['extra_expenses'] ?? 0);
                                ?>
                                    <?php if ($parts_total > 0): ?>
                                    <div class="order-amount-cell__parts small text-white-75"
                                         title="<?php echo e($parts_tip); ?>"
                                         tabindex="0"
                                         role="note"
                                         aria-label="<?php echo e(__('parts_cost') . ': ' . $parts_tip); ?>">
                                        <span class="order-amount-cell__label"><?php echo e(__('parts_cost')); ?></span>
                                        <span class="financial-number"><?php echo formatMoney($parts_total); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($extra_exp > 0): ?>
                                    <div class="order-amount-cell__extra small text-danger"
                                         title="<?php echo e(__('extra_expenses')); ?>">
                                        <span class="order-amount-cell__label"><?php echo e(__('extra_expenses')); ?></span>
                                        <span class="financial-number"><?php echo formatMoney($extra_exp); ?></span>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4 mobile-row-actions" data-label="">
                                <?php
                                    $can_quick = hasPermission('admin_access')
                                        || (($_SESSION['role'] ?? '') === 'technician'
                                            && (int)($order['technician_id'] ?? 0) === (int)($_SESSION['tech_id'] ?? 0));
                                ?>
                                <?php
                                    $terminal_statuses = ['Issued', 'Issued Without Repair', 'Repair Cancelled'];
                                    $canonical_status = canonicalOrderStatus($order['status']);
                                    $can_cancel = !in_array($canonical_status, $terminal_statuses, true);
                                    $show_quick = $can_quick && (
                                        !in_array($canonical_status, $terminal_statuses, true) || $can_cancel
                                    );
                                ?>
                                <?php // inline quick-status buttons removed; using dropdown only ?>
                                <div class="btn-group btn-group-sm shadow-sm order-row-actions">
                                    <a href="view_order.php?id=<?php echo (int)$order['id']; ?>" class="btn btn-outline-primary d-md-none" title="<?php echo e(__('open_btn')); ?>" aria-label="<?php echo e(__('open_btn')); ?>">
                                        <i class="fas fa-eye" aria-hidden="true"></i>
                                    </a>
                                    <?php if ($show_quick): ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' title="<?php echo e(__('quick_status')); ?>" aria-label="<?php echo e(__('quick_status')); ?>">
                                            <i class="fas fa-bolt text-primary"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <?php if ($canonical_status === 'Accepted'): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="#" data-id="<?php echo (int)$order['id']; ?>" data-status="Diagnostics"><i class="fas fa-stethoscope me-2 text-primary"></i><?php echo getStatusLabel('Diagnostics'); ?></a></li>
                                            <?php elseif (in_array($canonical_status, ['Diagnostics', 'Approval'], true)): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="#" data-id="<?php echo (int)$order['id']; ?>" data-status="In Repair"><i class="fas fa-tools me-2 text-warning"></i><?php echo getStatusLabel('In Repair'); ?></a></li>
                                            <?php elseif ($canonical_status === 'In Repair'): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="#" data-id="<?php echo (int)$order['id']; ?>" data-status="Ready"><i class="fas fa-check me-2 text-success"></i><?php echo getStatusLabel('Ready'); ?></a></li>
                                            <?php endif; ?>
                                            <?php if ($can_cancel): ?>
                                                <li><a class="dropdown-item quick-status-btn" href="#" data-id="<?php echo (int)$order['id']; ?>" data-status="Repair Cancelled"><i class="fas fa-ban me-2 text-danger"></i><?php echo getStatusLabel('Repair Cancelled'); ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                    <div class="dropdown">
                                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' title="<?php echo e(__('print')); ?>" aria-label="<?php echo e(__('print')); ?>">
                                            <i class="fas fa-print text-white-75"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_order.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Order #<?php echo (int)$order['id']; ?>"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                                            <li><a class="dropdown-item" href="#" data-crm-action="open-reception-language" data-crm-id="<?php echo (int)$order['id']; ?>"><i class="fas fa-file-import me-2 text-info"></i> <?php echo __('reception_act_thermal'); ?></a></li>
                                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_workshop.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Workshop Order #<?php echo (int)$order['id']; ?>"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_thermal.php?id=<?php echo (int)$order['id']; ?>" data-preview-title="Receipt #<?php echo (int)$order['id']; ?>"><i class="fas fa-receipt me-2 text-success"></i> <?php echo __('thermal_receipt'); ?></a></li>
                                        </ul>
                                    </div>
                                    <?php if (hasPermission('admin_access')): ?>
                                    <button type="button" class="btn btn-outline-secondary accounting-btn" data-id="<?php echo (int)$order['id']; ?>" title="<?php echo e(__('accounting')); ?>" aria-label="<?php echo e(__('accounting')); ?>">
                                        <i class="fas fa-file-invoice-dollar text-success"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<nav class="mt-4">
    <ul class="pagination justify-content-center">
        <?php
        // FIX #9: renamed to $query_params to avoid confusion with SQL $sql_params
        $query_params = $_GET;
        unset($query_params['p']);
        $url_prefix = ($qs = http_build_query($query_params)) ? "&$qs" : '';
        ?>
        
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $page - 1 . $url_prefix; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
        </li>

        <?php 
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        
        if ($start > 1) {
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=1'.$url_prefix.'">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }

        for ($i = $start; $i <= $end; $i++): 
        ?>
            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $i . $url_prefix; ?>">
                    <?php echo $i; ?>
                </a>
            </li>
        <?php endfor; ?>

        <?php 
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p='.$total_pages.$url_prefix.'">'.$total_pages.'</a></li>';
        }
        ?>

        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?p=<?php echo $page + 1 . $url_prefix; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
        </li>
    </ul>
</nav>
<?php endif; ?>



<!-- QR Popover Container -->
<div class="qr-popover" id="phoneQrPopover" role="dialog" aria-modal="false" aria-hidden="true" aria-label="<?php echo e(__('phone_show_qr')); ?>">
    <div class="qr-phone-label" id="qrPhoneLabel"></div>
    <div id="qrContainer"></div>
    <a href="#" class="btn btn-success qr-call-btn" id="qrCallBtn">
        <i class="fas fa-phone" aria-hidden="true"></i><?php echo __('call'); ?>
    </a>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="quickStatusToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="quickStatusToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>


<style>
/* ── IMEI duplicate badge ─────────────────────────────────────────────── */
.imei-dup-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    margin-left: 4px;
    border-radius: 9px;
    background: #e74c3c;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    line-height: 1;
    cursor: pointer;
    vertical-align: middle;
    transition: transform 0.15s ease, background 0.15s ease;
    user-select: none;
    white-space: nowrap;
}
.imei-dup-badge:hover {
    background: #c0392b;
    transform: scale(1.25);
}
</style>

<!-- IMEI Duplicates Modal -->
<div class="modal fade" id="imeiDuplicatesModal" tabindex="-1" aria-labelledby="imeiDuplicatesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark bg-opacity-25 border-secondary">
                <h5 class="modal-title" id="imeiDuplicatesModalLabel">
                    <i class="fas fa-copy me-2 text-danger"></i>
                    Дубликаты: <span id="imeiModalSn" class="text-warning font-monospace"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="imeiModalBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Загрузка...</span></div>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
            </div>
        </div>
    </div>
</div>


<!-- New Order Modal -->
<div class="modal fade" id="newOrderModal" tabindex="-1" data-bs-focus="false" aria-labelledby="newOrderModalTitle">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form action="api/add_order.php" method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?> <!-- FIX #6: CSRF protection -->
                <div class="modal-header new-order-modal__header">
                    <div class="new-order-modal__heading">
                        <h5 class="modal-title" id="newOrderModalTitle"><?php echo __('new_order'); ?></h5>
                    </div>
                    <div class="new-order-copy" role="group" aria-label="<?php echo e(__('copy_order')); ?>">
                        <label class="visually-hidden" for="copyOrderIdInput"><?php echo e(__('copy_order_placeholder')); ?></label>
                        <span class="new-order-copy__prefix" aria-hidden="true">#</span>
                        <input type="number"
                               id="copyOrderIdInput"
                               class="form-control new-order-copy__input"
                               placeholder="<?php echo e(__('copy_order_id_placeholder')); ?>"
                               aria-label="<?php echo e(__('copy_order_placeholder')); ?>"
                               inputmode="numeric"
                               autocomplete="off"
                               min="1">
                        <button type="button" class="btn new-order-copy__button" id="copyOrderBtn" title="<?php echo e(__('copy_order_btn')); ?>">
                            <i class="fas fa-copy" aria-hidden="true"></i>
                            <span><?php echo __('copy_order_btn'); ?></span>
                        </button>
                    </div>
                    <button type="button" class="new-order-modal__close" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- ═══ 1. КЛИЕНТ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user text-primary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('client'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <select name="customer_id" class="form-select select2-customer" style="width: 100%;" required>
                                    <option value=""><?php echo __('enter_name_or_phone'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary w-100" id="toggleNewCustomerPanelBtn" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel" aria-expanded="false">
                                    <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                                </button>
                            </div>
                            <!-- Inline New Customer Panel (collapsible, inside the same modal) -->
                            <div class="col-12">
                                <div class="collapse" id="inlineNewCustomerPanel">
                                    <div class="card border-secondary bg-dark bg-opacity-25 mt-2">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6 class="mb-0 text-white"><i class="fas fa-user-plus me-2 text-primary"></i><?php echo __('add_customer'); ?></h6>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div id="newCustomerInlineForm">
                                                <div class="mb-3">
                                                    <div class="btn-group w-100" role="group">
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_private" value="private" checked>
                                                        <label class="btn btn-outline-primary" for="inline_type_private"><?php echo __('private_person'); ?></label>
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_company" value="company">
                                                        <label class="btn btn-outline-primary" for="inline_type_company"><?php echo __('company_entity'); ?></label>
                                                    </div>
                                                </div>
                                                <div id="inline_company_fields" class="d-none border border-secondary p-3 rounded bg-transparent mb-3">
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('ico'); ?></label>
                                                        <div class="input-group">
                                                            <input type="text" name="ico" id="inline_ico_input" class="form-control" placeholder="12345678">
                                                            <button class="btn btn-info text-white" type="button" id="inline_btn_fetch_ares">
                                                                <i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('company_name'); ?></label>
                                                        <input type="text" name="company_name" id="inline_ares_name" class="form-control">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('dic'); ?></label>
                                                        <input type="text" name="dic" id="inline_ares_dic" class="form-control" placeholder="CZ12345678">
                                                    </div>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('name_col'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="first_name" id="inline_first_name" class="form-control">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('last_name_label'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="last_name" id="inline_last_name" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('phone'); ?> <span class="text-danger">*</span></label>
                                                        <input type="tel" name="phone" id="inline_phone" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="inline_email" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('address'); ?></label>
                                                        <textarea name="address" id="inline_address" class="form-control" rows="2"></textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="button" class="btn btn-success w-100" id="saveNewCustomerBtn">
                                                            <i class="fas fa-check me-2"></i><?php echo __('save'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 2. УСТРОЙСТВО ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-laptop text-info me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_device'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-12">
                                <span class="form-label device-type-picker__label" id="new-order-device-type-label"><?php echo __('device_type'); ?></span>
                                <div class="device-type-picker" role="radiogroup" aria-labelledby="new-order-device-type-label">
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="Phone" checked required>
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--phone" aria-hidden="true"></span>
                                            <span class="device-type-option__title"><?php echo __('Phone'); ?></span>
                                        </span>
                                    </label>
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="Notebook">
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--notebook" aria-hidden="true"></span>
                                            <span class="device-type-option__title"><?php echo __('Notebook'); ?></span>
                                        </span>
                                    </label>
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="PC">
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--pc" aria-hidden="true"></span>
                                            <span class="device-type-option__title">PC</span>
                                        </span>
                                    </label>
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="Tablet">
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--tablet" aria-hidden="true"></span>
                                            <span class="device-type-option__title"><?php echo __('Tablet'); ?></span>
                                        </span>
                                    </label>
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="HDD">
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--hdd" aria-hidden="true"></span>
                                            <span class="device-type-option__title"><?php echo __('HDD'); ?></span>
                                        </span>
                                    </label>
                                    <label class="device-type-option">
                                        <input type="radio" name="device_type" value="Other">
                                        <span class="device-type-option__surface">
                                            <span class="device-type-icon device-type-icon--other" aria-hidden="true"></span>
                                            <span class="device-type-option__title"><?php echo __('Other'); ?></span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('warranty_type'); ?></label>
                                <select name="order_type" class="form-select" required>
                                    <option value="Non-Warranty">🛠 <?php echo __('warranty_no'); ?></option>
                                    <option value="Warranty">📜 <?php echo __('warranty_yes'); ?></option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('device_brand'); ?></label>
                                <select name="device_brand" class="form-select select2-brand" style="width: 100%;" required>
                                    <option value=""><?php echo __('brand_placeholder'); ?></option>
                                    <?php foreach(getDeviceBrands() as $brand): ?>
                                        <option value="<?php echo $brand; ?>" <?php echo ($brand === 'APPLE') ? 'selected' : ''; ?>><?php echo $brand; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-3">
                                <label class="form-label"><?php echo __('device_model'); ?></label>
                                <select name="device_model" id="deviceModelSelect" class="form-select" style="width: 100%;" required>
                                    <option value=""><?php echo __('model_placeholder'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial'); ?></label>
                                <input type="text" name="serial_number" class="form-control sn-uppercase">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial_2'); ?></label>
                                <input type="text" name="serial_number_2" class="form-control sn-uppercase">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('pin'); ?></label>
                                <input type="text" name="pin_code" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('appearance'); ?></label>
                                <input type="text" name="appearance" class="form-control">
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 3. ПРОБЛЕМА ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_problem'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('priority'); ?></label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="priority" value="High" id="priorityHighOrders">
                                    <label class="form-check-label" for="priorityHighOrders"><?php echo __('high'); ?></label>
                                </div>
                            </div>
                            <?php if (!empty($order_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_note_templates) ? '4' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates'); ?></label>
                                <select class="form-select order-template-select" data-target="problem_description">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order_note_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_templates) ? '5' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates_notes'); ?></label>
                                <select class="form-select order-template-select" data-target="technician_notes">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_note_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('problem'); ?></label>
                                <textarea name="problem_description" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('notes'); ?> <?php echo __('comment_suffix'); ?></label>
                                <textarea name="technician_notes" class="form-control" rows="2" placeholder="<?php echo __('notes_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 4. ФИНАНСЫ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-coins text-success me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_financial'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('cost_est'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="estimated_cost" class="form-control" step="0.01">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 5. ИСПОЛНИТЕЛЬ ═══ -->
                    <div class="mb-0">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user-cog text-secondary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_execution'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('technician'); ?></label>
                                <select name="technician_id" class="form-select">
                                    <option value="">-- <?php echo __('technician'); ?> --</option>
                                    <?php
                                    // FIX #5: use pre-loaded $techs_list instead of re-querying
                                    foreach ($techs_list as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (($_SESSION['role'] ?? '') !== 'admin' && $t['id'] == ($_SESSION['tech_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('media_files'); ?></label>
                                <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                <div class="form-text"><?php echo __('upload_multiple_hint'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-dark bg-opacity-25 border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Quick View & Edit Modal -->
<div class="modal fade" id="quickOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickOrderTitle"><?php echo __('order_header'); ?> #</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="quickOrderBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between">
                <div>
                    <?php if(hasPermission('admin_access')): ?>
                    <button type="button" class="btn btn-outline-danger me-2" id="deleteQuickOrderBtn"><?php echo __('delete'); ?></button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                </div>
                <div class="d-flex gap-2">
                    <div class="dropdown">
                        <button class="btn btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-print me-2"></i> <?php echo __('print'); ?>
                        </button>
                        <ul class="dropdown-menu shadow">
                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_order.php?id=${o.id}" data-preview-title="<?php echo e(__('order_header')); ?> #${o.id}"><i class="fas fa-file-invoice me-2 text-primary"></i> <?php echo __('a4_invoice'); ?></a></li>
                            <li><a class="dropdown-item" href="#" data-crm-action="open-reception-language" data-crm-id="${o.id}"><i class="fas fa-file-import me-2 text-info"></i> <?php echo __('reception_act_thermal'); ?></a></li>
                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_workshop.php?id=${o.id}" data-preview-title="Workshop #${o.id}"><i class="fas fa-tools me-2 text-warning"></i> <?php echo __('work_order'); ?></a></li>
                            <li><a class="dropdown-item" href="#" data-crm-action="open-preview" data-preview-url="print_thermal.php?id=${o.id}" data-preview-title="<?php echo e(__('thermal_receipt')); ?> #${o.id}"><i class="fas fa-receipt me-2 text-success"></i> <?php echo __('thermal_receipt'); ?></a></li>
                        </ul>
                    </div>
                    <a href="#" id="fullViewBtn" class="btn btn-outline-primary"><?php echo __('open_full_view'); ?></a>
                    <button type="button" class="btn btn-primary" id="saveQuickOrderBtn"><?php echo __('save_changes'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>


<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="invoiceForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="order_id" id="invoiceOrderId">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('invoice'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3 border-bottom pb-2">
                        <div class="col-md-6">
                            <label class="form-label text-white-75 small"><?php echo __('client'); ?></label>
                            <div id="invoiceCustomerName" class="fw-bold"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75 small"><?php echo __('hint_problem_notes'); ?></label>
                            <div class="small text-danger" id="orderProblemHint"></div>
                            <div class="small text-white-75 italic" id="orderNotesHint"></div>
                        </div>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('invoice_number'); ?></label>
                            <input type="text" name="invoice_number" id="invoiceNumber" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('variable_symbol'); ?></label>
                            <input type="text" name="variable_symbol" id="variableSymbol" class="form-control">
                        </div>
                        
                        <div class="col-12 mt-3 mb-1">
                            <label class="form-label fw-bold"><?php echo __('invoice_items_label'); ?></label>
                            <div id="dynamic-items-container">
                                <!-- Dynamic rows here -->
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" id="dateIssue" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('date_tax'); ?></label>
                            <input type="date" name="date_tax" id="dateTax" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" id="dateDue" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('total_to_pay'); ?></label>
                            <div class="input-group">
                                <input type="number" name="total_amount" id="totalAmount" class="form-control" step="0.01" required>
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="button" id="saveInvoiceBtn" class="btn btn-success"><?php echo __('create_invoice'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Language Selection for Reception Act Modal -->
<div class="modal fade" id="receptionLangModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark bg-opacity-25 border-secondary border-0">
                <h6 class="modal-title fw-bold"><?php echo __('select_print_language'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="langOrderId">
                <div class="d-grid gap-3">
                    <button type="button" class="btn btn-outline-primary py-3 btn-lang-select" data-lang="ru">
                        <img src="https://flagcdn.com/w40/ru.png" class="me-2 rounded-1" width="24"> <?php echo __('lang_ru'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary py-3 btn-lang-select" data-lang="cs">
                        <img src="https://flagcdn.com/w40/cz.png" class="me-2 rounded-1" width="24"> <?php echo __('lang_cs'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>



<?php require_once __DIR__ . '/includes/partials/orders_scripts.php'; ?>
<?php require_once 'includes/footer.php'; ?>
