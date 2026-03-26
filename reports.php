<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Filter by date range (default to current week)
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('monday this week'));
$end_date = $_GET['end_date'] ?? date('Y-m-d', strtotime('sunday this week'));
$active_tab = $_GET['tab'] ?? 'staff_stats';
$selected_tech_id = $_GET['tech_id'] ?? null;

$is_admin = hasPermission('admin_access');
$is_tech = ($_SESSION['role'] ?? '') == 'technician';

// If technician, force them to see only their own stats and only the individual tab
if (!$is_admin && $is_tech) {
    $active_tab = 'individual_stats';
    $selected_tech_id = $_SESSION['tech_id'];
}

// Helper to get stats for a specific period and optional technician
function getDetailedStats($pdo, $start, $end, $tech_id = null) {
    $params = [$start . ' 00:00:00', $end . ' 23:59:59'];
    $tech_cond = $tech_id ? " AND technician_id = ?" : "";
    if ($tech_id) $params[] = $tech_id;

    // Received
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE (created_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $received = $stmt->fetchColumn();

    // In Progress
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'In Progress' AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $in_progress = $stmt->fetchColumn();

    // Completed/Collected (Done)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Completed', 'Collected') AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $completed = $stmt->fetchColumn();

    // Cancelled (Without repair)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'Cancelled' AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $cancelled = $stmt->fetchColumn();

    // ─── Financials ───────────────────────────────────────────────────────────
    // Finance date priority:
    //   1. payment_date from linked invoice (status='paid')  ← correct for accounting
    //   2. shipping_date of the order (fallback when no invoice exists)
    // Only 'Collected' orders are counted.
    //
    // IMPORTANT: Use a SEPARATE params array here, because:
    //  - $params above has layout: [start_dt, end_dt, tech_id]
    //  - This query needs: [start_date, end_date, tech_id?] in THAT order
    $fin_params = [$start, $end];
    $fin_tech_cond = "";
    if ($tech_id) {
        $fin_tech_cond = " AND o.technician_id = ?";
        $fin_params[] = $tech_id;
    }

    $sql_orders = "
        SELECT
            o.id,
            o.final_cost,
            o.estimated_cost,
            o.extra_expenses,
            o.technician_id,
            o.shipping_date,
            inv.total_amount AS invoice_amount,
            COALESCE(inv.payment_date, DATE(o.shipping_date)) AS finance_date,
            (SELECT SUM(oi2.quantity * invt.cost_price)
             FROM order_items oi2
             JOIN inventory invt ON oi2.inventory_id = invt.id
             WHERE oi2.order_id = o.id) AS inventory_cost
        FROM orders o
        LEFT JOIN invoices inv ON inv.order_id = o.id AND inv.status = 'paid'
        WHERE o.status = 'Collected'
          AND COALESCE(inv.payment_date, DATE(o.shipping_date)) BETWEEN ? AND ?
    " . $fin_tech_cond;

    $stmt = $pdo->prepare($sql_orders);
    $stmt->execute($fin_params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $revenue          = 0;
    $expenses         = 0;
    $parts_cost       = 0;
    $engineer_earnings = 0;

    // Load all engineer rates once
    $stmt_rates = $pdo->query("SELECT id, engineer_rate FROM technicians");
    $rates = [];
    while ($row = $stmt_rates->fetch(PDO::FETCH_ASSOC)) {
        $rates[$row['id']] = (float)($row['engineer_rate'] ?? 50);
    }
    $engineer_rate = $tech_id ? ($rates[$tech_id] ?? 50) : 50;

    foreach ($orders as $o) {
        // Revenue priority: final_cost → invoice total_amount → estimated_cost
        $rev    = $o['final_cost'] !== null ? (float)$o['final_cost']
                : ($o['invoice_amount'] !== null ? (float)$o['invoice_amount']
                : (float)($o['estimated_cost'] ?? 0));
        $exp    = (float)($o['extra_expenses'] ?? 0);
        $p_cost = (float)($o['inventory_cost'] ?? 0);

        $revenue    += $rev;
        $expenses   += $exp;
        $parts_cost += $p_cost;

        // Net = revenue minus parts and extra expenses.
        // Technician earns 0 if net is negative (does not 'pay' the SC back).
        $net  = $rev - $p_cost - $exp;
        if ($net < 0) $net = 0;

        $rate = $rates[$o['technician_id']] ?? $engineer_rate;
        $engineer_earnings += $net * ($rate / 100);
    }

    $net_revenue = $revenue - $parts_cost - $expenses;
    $sc_profit   = $net_revenue - $engineer_earnings;

    return [
        'received' => $received,
        'in_progress' => $in_progress,
        'completed' => $completed,
        'cancelled' => $cancelled,
        'revenue' => $revenue,
        'expenses' => $expenses,
        'parts_cost' => $parts_cost,
        'net_revenue' => $net_revenue,
        'engineer_rate' => $engineer_rate,
        'earnings' => $engineer_earnings,
        'profit' => $sc_profit
    ];
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h2 class="mb-0"><i class="fas fa-chart-line me-2 text-primary"></i><?php echo __('reports'); ?></h2>
        <form class="d-flex gap-2">
            <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
            <?php if($selected_tech_id): ?><input type="hidden" name="tech_id" value="<?php echo $selected_tech_id; ?>"><?php endif; ?>
            <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo $start_date; ?>">
            <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo $end_date; ?>">
            <button type="submit" class="btn btn-sm btn-primary px-3"><?php echo __('update_btn'); ?></button>
        </form>
    </div>

    <?php if ($is_admin): ?>
    <!-- Tab Navigation -->
    <ul class="nav nav-pills mb-4 glass-panel p-2">
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'staff_stats' ? 'active' : 'text-white'; ?>" href="?tab=staff_stats&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-users-cog me-2"></i><?php echo __('staff_stats'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'general_stats' ? 'active' : 'text-white'; ?>" href="?tab=general_stats&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-chart-pie me-2"></i><?php echo __('general_stats'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'individual_stats' ? 'active' : 'text-white'; ?>" href="?tab=individual_stats&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
                <i class="fas fa-user me-2"></i><?php echo __('individual_stats'); ?>
            </a>
        </li>
    </ul>
    <?php endif; ?>

    <div class="tab-content glass-panel p-4">
        
        <!-- STAFF STATS TAB -->
        <?php if ($active_tab == 'staff_stats'): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th><?php echo __('technician'); ?></th>
                            <th class="text-center"><?php echo __('repaired_count'); ?></th>
                            <th class="text-end"><?php echo __('total_revenue'); ?></th>
                            <th class="text-end text-muted small"><?php echo __('parts_cost'); ?></th>
                            <th class="text-end text-muted small"><?php echo __('expenses_label'); ?></th>
                            <th class="text-end"><?php echo __('earned'); ?></th>
                            <th class="text-end"><?php echo __('sc_income'); ?></th>
                            <th class="text-center" style="width:60px">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $techs = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
                        $totals = [
                            'received' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0,
                            'revenue' => 0, 'parts_cost' => 0, 'expenses' => 0,
                            'earnings' => 0, 'profit' => 0
                        ];

                        foreach ($techs as $t):
                            $s = getDetailedStats($pdo, $start_date, $end_date, $t['id']);
                            $totals['received'] += $s['received'];
                            $totals['in_progress'] += $s['in_progress'];
                            $totals['completed'] += $s['completed'];
                            $totals['cancelled'] += $s['cancelled'];
                            $totals['revenue'] += $s['revenue'];
                            $totals['parts_cost'] += $s['parts_cost'];
                            $totals['expenses'] += $s['expenses'];
                            $totals['earnings'] += $s['earnings'];
                            $totals['profit'] += $s['profit'];
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($t['name']); ?></strong>
                                <a href="?tab=individual_stats&tech_id=<?php echo $t['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="ms-2 small text-primary"><i class="fas fa-external-link-alt"></i></a>
                            </td>
                            <td class="text-center">
                                <a href="javascript:void(0)" onclick="showOrdersModal(<?php echo $t['id']; ?>, 'completed', '<?php echo __('repaired_count'); ?>')" class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 text-decoration-none">
                                    <?php echo $s['completed']; ?>
                                </a>
                            </td>
                            <td class="text-end"><?php echo formatMoney($s['revenue']); ?></td>
                            <td class="text-end text-muted small"><?php echo $s['parts_cost'] > 0 ? '-'.formatMoney($s['parts_cost']) : '—'; ?></td>
                            <td class="text-end text-muted small"><?php echo $s['expenses'] > 0 ? '-'.formatMoney($s['expenses']) : '—'; ?></td>
                            <td class="text-end fw-bold text-primary"><?php echo formatMoney($s['earnings']); ?></td>
                            <td class="text-end text-success fw-bold"><?php echo formatMoney($s['profit']); ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?php echo $s['engineer_rate']; ?>%</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold border-top-2">
                        <tr>
                            <td><?php echo __('total_period'); ?></td>
                            <td class="text-center"><?php echo $totals['completed']; ?></td>
                            <td class="text-end"><?php echo formatMoney($totals['revenue']); ?></td>
                            <td class="text-end text-muted small">-<?php echo formatMoney($totals['parts_cost']); ?></td>
                            <td class="text-end text-muted small">-<?php echo formatMoney($totals['expenses']); ?></td>
                            <td class="text-end text-primary"><?php echo formatMoney($totals['earnings']); ?></td>
                            <td class="text-end text-success"><?php echo formatMoney($totals['profit']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>

        <!-- GENERAL STATS TAB -->
        <?php if ($active_tab == 'general_stats'): 
            $gs = getDetailedStats($pdo, $start_date, $end_date);
        ?>
            <div class="row g-4 mb-5">
                <div class="col-md-3">
                    <div class="card border-0 bg-primary bg-opacity-10 p-3 text-center">
                        <h6 class="text-uppercase small text-muted mb-2"><?php echo __('received_devices'); ?></h6>
                        <h2 class="mb-0 text-primary"><?php echo $gs['received']; ?></h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-success bg-opacity-10 p-3 text-center">
                        <h6 class="text-uppercase small text-muted mb-2"><?php echo __('repaired'); ?></h6>
                        <h2 class="mb-0 text-success"><?php echo $gs['completed']; ?></h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-danger bg-opacity-10 p-3 text-center">
                        <h6 class="text-uppercase small text-muted mb-2"><?php echo __('cancelled_rejected'); ?></h6>
                        <h2 class="mb-0 text-danger"><?php echo $gs['cancelled']; ?></h2>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 bg-info bg-opacity-10 p-3 text-center">
                        <h6 class="text-uppercase small text-muted mb-2"><?php echo __('efficiency'); ?></h6>
                        <h2 class="mb-0 text-info"><?php echo $gs['received'] > 0 ? round(($gs['completed'] / $gs['received']) * 100) : 0; ?>%</h2>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-6">
                    <h5 class="mb-3 border-bottom pb-2"><?php echo __('financial_result'); ?></h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span><?php echo __('total_revenue'); ?>:</span>
                            <span class="fw-bold"><?php echo formatMoney($gs['revenue']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span><?php echo __('parts_costs'); ?>:</span>
                            <span class="text-danger">- <?php echo formatMoney($gs['parts_cost']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span><?php echo __('extra_expenses'); ?>:</span>
                            <span class="text-danger">- <?php echo formatMoney($gs['expenses']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span><?php echo __('engineer_payouts'); ?>:</span>
                            <span class="text-danger">- <?php echo formatMoney($gs['earnings']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0 bg-transparent p-2 mt-2 border-top">
                            <span class="fw-bold text-white"><?php echo __('net_profit'); ?>:</span>
                            <span class="text-success fw-bold fs-5"><?php echo formatMoney($gs['profit']); ?></span>
                        </li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h5 class="mb-3 border-bottom pb-2"><?php echo __('device_types_stats'); ?></h5>
                    <?php
                    $stmt = $pdo->prepare("SELECT device_type, COUNT(*) as count FROM orders WHERE (created_at BETWEEN ? AND ?) GROUP BY device_type ORDER BY count DESC");
                    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                    $types = $stmt->fetchAll();
                    foreach ($types as $t):
                        $percent = $gs['received'] > 0 ? ($t['count'] / $gs['received']) * 100 : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?php echo htmlspecialchars(__($t['device_type'])); ?></span>
                            <span class="fw-bold"><?php echo $t['count']; ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- INDIVIDUAL STATS TAB -->
        <?php if ($active_tab == 'individual_stats'): ?>
            <?php if ($is_admin): ?>
            <div class="row mb-4">
                <div class="col-md-4">
                    <form method="GET">
                        <input type="hidden" name="tab" value="individual_stats">
                        <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                        <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                        <label class="form-label small text-muted"><?php echo __('select_employee_label'); ?></label>
                        <select name="tech_id" class="form-select" onchange="this.form.submit()">
                            <option value=""><?php echo __('select_employee_option'); ?></option>
                            <?php 
                            $techs_list = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
                            foreach($techs_list as $tl): ?>
                                <option value="<?php echo $tl['id']; ?>" <?php echo $selected_tech_id == $tl['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selected_tech_id): 
                $is = getDetailedStats($pdo, $start_date, $end_date, $selected_tech_id);
            ?>
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="card p-3 border shadow-none text-center">
                            <div class="small text-muted mb-1"><?php echo __('repairs_done'); ?></div>
                            <h3 class="mb-0 text-success"><?php echo $is['completed']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card p-3 border shadow-none text-center">
                            <div class="small text-muted mb-1"><?php echo __('cancellations'); ?></div>
                            <h3 class="mb-0 text-danger"><?php echo $is['cancelled']; ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card p-3 border shadow-none text-center">
                            <div class="small text-muted mb-1"><?php echo __('engineer_earnings'); ?> <span class="badge bg-secondary"><?php echo $is['engineer_rate']; ?>%</span></div>
                            <h3 class="mb-0 text-primary"><?php echo formatMoney($is['earnings']); ?></h3>
                        </div>
                    </div>
                    <?php if ($is_admin): ?>
                    <div class="col-md-3">
                        <div class="card p-3 border shadow-none text-center">
                            <div class="small text-muted mb-1"><?php echo __('sc_net_income'); ?></div>
                            <h3 class="mb-0 text-success"><?php echo formatMoney($is['profit']); ?></h3>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <h5 class="mb-3 mt-5"><?php echo __('completed_works_list'); ?></h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th><?php echo __('order_id'); ?></th>
                                <th><?php echo __('issue_date'); ?></th>
                                <th><?php echo __('device'); ?></th>
                                <th><?php echo __('client'); ?></th>
                                <th class="text-end"><?php echo __('sum'); ?></th>
                                <th class="text-end text-muted small"><?php echo __('parts_cost'); ?></th>
                                <th class="text-end"><?php echo __('profit'); ?></th>
                                <th class="text-end"><?php echo __('earnings_col'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $current_url = urlencode($_SERVER['REQUEST_URI']);
                            $stmt = $pdo->prepare("
                                SELECT o.*, c.first_name, c.last_name,
                                    COALESCE(inv.payment_date, DATE(o.shipping_date)) AS finance_date,
                                    (SELECT SUM(oi.quantity * invt.cost_price) FROM order_items oi JOIN inventory invt ON oi.inventory_id = invt.id WHERE oi.order_id = o.id) as inventory_cost
                                FROM orders o
                                JOIN customers c ON o.customer_id = c.id
                                LEFT JOIN invoices inv ON inv.order_id = o.id AND inv.status = 'paid'
                                WHERE o.technician_id = ? AND o.status = 'Collected'
                                  AND COALESCE(inv.payment_date, DATE(o.shipping_date)) BETWEEN ? AND ?
                                ORDER BY finance_date DESC
                            ");
                            $stmt->execute([$selected_tech_id, $start_date, $end_date]);
                            
                            while($r = $stmt->fetch()): 
                                $rev = $r['final_cost'] !== null ? $r['final_cost'] : $r['estimated_cost'];
                                $rev = floatval($rev ?: 0);
                                $p_cost = floatval($r['inventory_cost'] ?: 0);
                                $e_cost = floatval($r['extra_expenses'] ?: 0);
                                $net = $rev - $p_cost - $e_cost;
                                if ($net < 0) $net = 0;
                                $earn = $net * ($is['engineer_rate'] / 100);
                            ?>
                            <tr>
                                <td><a href="view_order.php?id=<?php echo $r['id']; ?>&return=<?php echo $current_url; ?>" class="fw-bold">#<?php echo $r['id']; ?></a></td>
                                <td><?php echo date('d.m.Y', strtotime($r['finance_date'])); ?></td>
                                <td><?php echo htmlspecialchars($r['device_brand'] . ' ' . $r['device_model']); ?></td>
                                <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatMoney($rev); ?></td>
                                <td class="text-end text-muted small"><?php echo $p_cost > 0 ? '-'.formatMoney($p_cost) : '—'; ?></td>
                                <td class="text-end"><?php echo formatMoney($net); ?></td>
                                <td class="text-end fw-bold text-primary"><?php echo formatMoney($earn); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-user-tie fa-3x mb-3 opacity-25"></i>
                    <p><?php echo __('select_tech_prompt'); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<!-- Modal for Report Orders List -->
<div class="modal fade" id="reportOrdersModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content glass-panel border-0 shadow-lg">
            <div class="modal-header border-bottom-0 py-3">
                <h5 class="modal-title fw-bold text-white" id="reportOrdersModalTitle"><?php echo __('orders_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="modalLoading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted"><?php echo __('loading_data'); ?></p>
                </div>
                <div id="modalTableWrapper" class="table-responsive d-none">
                    <table class="table table-hover align-middle mb-0 text-white">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th><?php echo __('device'); ?></th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="text-end pe-4"><?php echo __('sum'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="reportOrdersTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-top-0 py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function showOrdersModal(techId, type, title) {
    const modal = new bootstrap.Modal(document.getElementById('reportOrdersModal'));
    document.getElementById('reportOrdersModalTitle').innerText = title + ' <?php echo __('detailed_suffix'); ?>';
    document.getElementById('modalLoading').classList.remove('d-none');
    document.getElementById('modalTableWrapper').classList.add('d-none');
    
    modal.show();

    const startDate = "<?php echo $start_date; ?>";
    const endDate = "<?php echo $end_date; ?>";
    const currentUrl = encodeURIComponent(window.location.href);

    fetch(`api/get_orders_for_report.php?tech_id=${techId}&type=${type}&start_date=${startDate}&end_date=${endDate}`)
        .then(response => response.json())
        .then(res => {
            document.getElementById('modalLoading').classList.add('d-none');
            const tbody = document.getElementById('reportOrdersTableBody');
            tbody.innerHTML = '';

            if (res.success && res.data.length > 0) {
                res.data.forEach(order => {
                    const row = document.createElement('tr');
                    const cost = order.final_cost > 0 ? order.final_cost : (order.estimated_cost || 0);
                    
                    const tdId = document.createElement('td');
                    tdId.className = 'ps-4';
                    const aId = document.createElement('a');
                    aId.href = 'view_order.php?id=' + order.id + '&return=' + currentUrl;
                    aId.className = 'fw-bold text-decoration-none';
                    aId.textContent = '#' + order.id;
                    tdId.appendChild(aId);

                    const tdDevice = document.createElement('td');
                    const strong = document.createElement('strong');
                    strong.textContent = order.device_brand || '';
                    tdDevice.appendChild(strong);
                    tdDevice.append(' ' + (order.device_model || ''));

                    const tdClient = document.createElement('td');
                    tdClient.textContent = (order.first_name || '') + ' ' + (order.last_name || '');

                    const tdStatus = document.createElement('td');
                    const badge = document.createElement('span');
                    badge.className = 'badge ' + getStatusBadgeClass(order.status);
                    badge.textContent = getStatusLabel(order.status);
                    tdStatus.appendChild(badge);

                    const tdSum = document.createElement('td');
                    tdSum.className = 'text-end pe-4 fw-bold';
                    tdSum.textContent = parseFloat(cost).toFixed(2) + ' <?php echo get_setting('currency', 'Kč'); ?>';

                    row.appendChild(tdId);
                    row.appendChild(tdDevice);
                    row.appendChild(tdClient);
                    row.appendChild(tdStatus);
                    row.appendChild(tdSum);
                    tbody.appendChild(row);
                });
                document.getElementById('modalTableWrapper').classList.remove('d-none');
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><?php echo __('no_orders_found'); ?></td></tr>';
                document.getElementById('modalTableWrapper').classList.remove('d-none');
            }
        })
        .catch(err => {
            console.error(err);
            // Close modal if error to prevent stuck state
            const modalEl = document.getElementById('reportOrdersModal');
            const modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
            showAlert('<?php echo __('error_loading_data'); ?>');
        });
}

function getStatusBadgeClass(status) {
    switch(status) {
        case 'New': return 'bg-primary';
        case 'Pending Approval': return 'bg-info text-dark';
        case 'In Progress': return 'bg-warning';
        case 'Waiting for Parts': return 'bg-secondary';
        case 'Completed': return 'bg-success';
        case 'Collected': return 'bg-info text-dark';
        case 'Cancelled': return 'bg-danger';
        default: return 'bg-secondary';
    }
}

function getStatusLabel(status) {
    const labels = {
        'New': '<?php echo __('new'); ?>',
        'Новый': '<?php echo __('new'); ?>',
        'Pending Approval': '<?php echo __('pending_approval'); ?>',
        'На согласовании': '<?php echo __('pending_approval'); ?>',
        'In Progress': '<?php echo __('in_progress'); ?>',
        'В работе': '<?php echo __('in_progress'); ?>',
        'Waiting for Parts': '<?php echo __('waiting_parts'); ?>',
        'Ожидание запчастей': '<?php echo __('waiting_parts'); ?>',
        'Completed': '<?php echo __('status_completed'); ?>',
        'Готов': '<?php echo __('status_completed'); ?>',
        'Collected': '<?php echo __('status_collected'); ?>',
        'Выдан': '<?php echo __('status_collected'); ?>',
        'Cancelled': '<?php echo __('status_cancelled'); ?>',
        'Отменен': '<?php echo __('status_cancelled'); ?>'
    };
    return labels[status] || status;
}

// Reload page if returned from order edit to ensure fresh data
window.addEventListener('pageshow', function(event) {
    if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
        location.reload();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>

