<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Filter for Dashboard
$filter_status = $_GET['filter'] ?? null;
$dashboard_status_groups = getDashboardStatusGroups();
$canonical_filter_status = canonicalOrderStatus($filter_status ?? '');

// Technicians always see only orders assigned to them.
$dashboard_technician_id = null;
if (isTechnicianScoped()) {
    $dashboard_technician_id = (int)$_SESSION['tech_id'];
}

// Count for Stats
$dashboard_counts = countOrdersByStatusGroups($dashboard_status_groups, $dashboard_technician_id);
$new_count = $dashboard_counts['new'];
$pending_count = $dashboard_counts['pending'];
$progress_count = $dashboard_counts['progress'];
$ready_count = $dashboard_counts['ready'];
$dashboard_total = $new_count + $pending_count + $progress_count + $ready_count;

// Online Techs (Last 5 minutes) - Admin or those with admin_access
$online_count = 0;
if (hasPermission('admin_access')) {
    $online_count = $pdo->query("SELECT COUNT(*) FROM technicians WHERE last_seen > (NOW() - INTERVAL 5 MINUTE) AND is_active = 1")->fetchColumn();
}

?>

<section class="workspace-overview workspace-overview--dashboard ui-ready" aria-labelledby="dashboard-overview-title">
    <div class="workspace-overview__head">
        <div class="workspace-overview__copy">
        <h1 id="dashboard-overview-title"><?php echo __('dashboard'); ?></h1>
        <p class="workspace-overview__total">
            <?php echo __('all_orders'); ?>: <strong class="financial-number"><?php echo $dashboard_total; ?></strong>
        </p>
    </div>
    <div class="page-actions">
        <a href="orders.php" class="btn btn-outline-secondary"><?php echo __('all_orders'); ?></a>
        <a class="btn btn-primary" href="orders.php?new_order=1">
            <i class="fas fa-plus" aria-hidden="true"></i>
            <span><?php echo __('new_order'); ?></span>
        </a>
    </div>
    </div>

    <div class="workspace-overview__metrics workspace-overview__metrics--five" aria-label="<?php echo e(__('status')); ?>">
        <a href="?filter=Accepted" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Accepted' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('new_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $new_count; ?></strong>
                <?php echo getStatusBadge('Accepted'); ?>
            </span>
        </a>
        <a href="?filter=Approval" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Approval' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('pending_approval_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $pending_count; ?></strong>
                <?php echo getStatusBadge('Approval'); ?>
            </span>
        </a>
        <a href="?filter=In%20Repair" class="workspace-overview__metric <?php echo $canonical_filter_status == 'In Repair' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('in_progress_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $progress_count; ?></strong>
                <?php echo getStatusBadge('In Repair'); ?>
            </span>
        </a>
        <a href="?filter=Ready" class="workspace-overview__metric <?php echo $canonical_filter_status == 'Ready' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo __('completed_orders'); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo $ready_count; ?></strong>
                <?php echo getStatusBadge('Ready'); ?>
            </span>
        </a>
        <?php if (hasPermission('admin_access')): ?>
            <div class="workspace-overview__metric" data-bs-toggle="tooltip" title="<?php echo e(__('online_techs_tooltip')); ?>">
                <span class="workspace-overview__metric-label"><?php echo __('online_techs'); ?></span>
                <span class="workspace-overview__metric-data">
                    <strong class="workspace-overview__metric-value financial-number"><?php echo $online_count; ?></strong>
                    <span class="workspace-overview__metric-note"><?php echo __('technician'); ?></span>
                </span>
            </div>
        <?php else: ?>
            <div class="workspace-overview__metric">
                <span class="workspace-overview__metric-label"><?php echo __('technician'); ?></span>
                <span class="workspace-overview__metric-data">
                    <strong class="workspace-overview__metric-value financial-number">#<?php echo (int)($_SESSION['tech_id'] ?? 0); ?></strong>
                    <span class="workspace-overview__metric-note"><?php echo e($_SESSION['full_name'] ?? ''); ?></span>
                </span>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="row">
    <div class="col-md-8">
        <div class="card glass-card dashboard-orders-card ui-ready">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php 
                    if ($canonical_filter_status == 'Accepted') echo __('new_orders');
                    elseif ($canonical_filter_status == 'Approval') echo __('pending_approval_orders');
                    elseif (in_array($canonical_filter_status, ['Diagnostics', 'In Repair'], true)) echo __('in_progress_orders');
                    elseif ($canonical_filter_status == 'Ready') echo __('completed_orders');
                    else echo __('recent_orders'); 
                    ?>
                </h5>
                <?php
                $active_search = normalizeSearchQuery((string)($_GET['search'] ?? ''));
                $orders_search_href = 'orders.php' . ($active_search !== '' ? ('?search=' . rawurlencode($active_search)) : '');
                ?>
                <?php if ($active_search !== ''): ?>
                    <a href="index.php<?php echo $filter_status ? ('?filter=' . rawurlencode((string)$filter_status)) : ''; ?>" class="btn btn-sm btn-outline-secondary"><?php echo __('cancel'); ?></a>
                    <a href="<?php echo e($orders_search_href); ?>" class="btn btn-sm btn-primary"><?php echo __('all_orders'); ?></a>
                <?php elseif ($filter_status): ?>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary"><?php echo __('show_all'); ?></a>
                <?php else: ?>
                    <a href="orders.php" class="btn btn-sm btn-primary"><?php echo __('all_orders'); ?></a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive table-scroll-touch dashboard-orders-table-wrap">
                    <table class="table table-hover align-middle mb-0 dashboard-orders-table table-mobile-cards">
                        <thead class="bg-transparent sticky-top" style="z-index: 10;">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('device_model'); ?></th>
                                <th><?php echo __('problem'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="text-end pe-4"><?php echo __('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Same search engine as Orders page search-shell
                            // (scoring + optional-index fallback + tech scope).
                            $orders_list = [];
                            $has_media_ids = [];
                            try {
                                $search_result = searchOrdersList(
                                    $pdo,
                                    (string)($_GET['search'] ?? ''),
                                    $dashboard_technician_id,
                                    $filter_status,
                                    15,
                                    0,
                                    false
                                );
                                $orders_list = $search_result['orders'];

                                if (!empty($orders_list)) {
                                    $order_ids = array_column($orders_list, 'id');
                                    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                                    $m_stmt = $pdo->prepare("SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id");
                                    $m_stmt->execute($order_ids);
                                    $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
                                }
                            } catch (PDOException $e) {
                                error_log('index.php order search error: ' . $e->getMessage());
                                $orders_list = [];
                            }

                            $found = false;
                            foreach($orders_list as $r):
                                $found = true;
                                $icon = getDeviceIcon($r['device_type']);

                                $has_media = isset($has_media_ids[$r['id']]);
                            ?>
                            <tr <?php if($r['priority'] == 'High') echo 'class="priority-high-row"'; ?>>
                                <td class="ps-4" data-label="ID">
                                    <a href="view_order.php?id=<?php echo $r['id']; ?>" class="fw-bold text-decoration-none mobile-order-link">#<?php echo $r['id']; ?></a>
                                    <?php if($has_media): ?>
                                        <i class="fas fa-camera text-info ms-1" title="<?php echo __('has_media'); ?>"></i>
                                    <?php endif; ?>
                                    <?php if($r['priority'] == 'High'): ?>
                                        <div class="priority-chip priority-chip-animated">
                                            <i class="fas fa-bolt"></i>
                                            <span><?php echo __('high'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?php echo e(__('client')); ?>">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></div>
                                    <div class="small text-white-75"><?php echo htmlspecialchars($r['phone']); ?></div>
                                </td>
                                <td data-label="<?php echo e(__('device_model')); ?>">
                                    <div class="fw-medium text-primary"><?php echo $icon; ?> <?php echo htmlspecialchars($r['device_brand']); ?></div>
                                    <div class="small text-white-75"><?php echo htmlspecialchars($r['device_model']); ?></div>
                                </td>
                                <td data-label="<?php echo e(__('problem')); ?>">
                                    <div class="small problem-snippet"><?php echo htmlspecialchars(mb_strimwidth($r['problem_description'], 0, 56, "...")); ?></div>
                                    <span class="badge bg-transparent border border-secondary text-white-75 mt-2"><i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($r['tech_name'] ?? '---'); ?></span>
                                </td>
                                <td data-label="<?php echo e(__('status')); ?>"><?php echo getStatusBadge($r['status']); ?></td>
                                <td class="text-end pe-4" data-label="<?php echo e(__('amount')); ?>"><strong><?php echo formatMoney($r['final_cost'] ?? $r['estimated_cost']); ?></strong></td>
                            </tr>
                            <?php endforeach; 
                            
                            if (!$found): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-white-75">
                                        <i class="fas fa-folder-open fa-2x mb-3 d-block opacity-25"></i>
                                        <?php echo __('not_found'); ?>
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
                <h5 class="mb-0"><?php echo __('quick_actions'); ?></h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a class="btn btn-outline-primary" href="orders.php?new_order=1"><i class="fas fa-plus me-2" aria-hidden="true"></i> <?php echo __('new_order'); ?></a>
                    <?php if (hasPermission('admin_access')): ?>
                    <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-user-plus me-2"></i> <?php echo __('customers'); ?></a>
                    <a href="inventory.php" class="btn btn-outline-info"><i class="fas fa-search me-2"></i> <?php echo __('check_stock'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Dashboard Right Column (Techs list if Admin) -->
        <?php if (hasPermission('admin_access')): ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('online_techs'); ?></h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php
                    $all_techs = $pdo->query("SELECT name, last_seen FROM technicians WHERE is_active = 1 ORDER BY last_seen DESC")->fetchAll();
                    foreach ($all_techs as $tech):
                        $is_online = (strtotime($tech['last_seen'] ?? '0') > strtotime("-5 minutes"));
                    ?>
                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <div class="position-relative me-3">
                                <i class="fas fa-user-circle fa-2x text-white-75 opacity-50"></i>
                                <span class="position-absolute bottom-0 end-0 p-1 <?php echo $is_online ? 'bg-success' : 'bg-secondary'; ?> border border-light rounded-circle"></span>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($tech['name']); ?></div>
                                <small class="text-white-75">
                                    <?php echo $is_online ? __('tech_online') : __('tech_last_seen') . ': ' . ($tech['last_seen'] ? date('H:i, d.m', strtotime($tech['last_seen'])) : __('never')); ?>
                                </small>
                            </div>
                        </div>
                        <?php if ($is_online): ?>
                            <span class="status-pill status-pill--ready"><?php echo __('tech_online'); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>



<?php require_once 'includes/footer.php'; ?>

