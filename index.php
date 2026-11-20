<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/work_queue.php';

// Preserve bookmarked status filters from the former recent-orders dashboard.
$legacyFilter = is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '';
$search = normalizeSearchQuery(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
if ($legacyFilter !== '') {
    $target = ['filter' => $legacyFilter, 'search' => $search];
    if ($search === '' && in_array(canonicalOrderStatus($legacyFilter), ['Ready', 'Approval'], true)) {
        header('Location: index.php?queue=' . (canonicalOrderStatus($legacyFilter) === 'Ready' ? 'ready' : 'approval'));
    } else {
        header('Location: orders.php?' . http_build_query($target));
    }
    exit;
}
require_once 'includes/header.php';

$queueLabels = [
    'attention' => __('queue_attention'),
    'overdue' => __('queue_overdue'),
    'approval' => __('pending_approval_orders'),
    'parts' => __('waiting_parts'),
    'ready' => __('queue_ready'),
];
$requestedQueue = is_string($_GET['queue'] ?? null) ? $_GET['queue'] : '';
$requestedPage = filter_var($_GET['p'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
try {
    $work = crmWorkQueue($pdo, $requestedQueue, $requestedPage, $search === '');
    $queue = $work['queue'];
    $isAdmin = $work['scope']['is_admin'];
    $rows = $work['rows'];
    $searchUrl = 'orders.php?' . http_build_query(['search' => $search]);
    $total = $work['counts'][$queue];
    if ($search !== '') {
        $results = searchOrdersList($pdo, $search, $work['scope']['technician_id'], null, 15);
        $rows = $results['orders'];
        $total = $results['total'];
    }
} catch (Throwable $error) {
    error_log('Dashboard work queue: ' . $error->getMessage());
    ?>
    <div role="alert" class="alert alert-danger"><?= e(__('queue_unavailable')) ?>
        <a href="orders.php" class="alert-link"><?= e(__('all_orders')) ?></a>
    </div>
    <?php require 'includes/footer.php'; return;
}
?>

<section class="workspace-overview workspace-overview--dashboard" aria-labelledby="queue-title">
    <div class="workspace-overview__head">
        <div class="workspace-overview__copy"><h1 id="queue-title"><?= e(__($isAdmin ? 'queue_workshop' : 'queue_personal')) ?></h1></div>
        <div class="page-actions order-list-primary">
            <a class="btn btn-primary" href="orders.php?new_order=1"><span aria-hidden="true">+</span> <?= e(__('new_order')) ?></a>
        </div>
    </div>
    <nav class="workspace-overview__metrics workspace-overview__metrics--four" aria-label="<?= e(__('status')) ?>">
        <?php foreach (['overdue', 'approval', 'parts', 'ready'] as $key): ?>
            <a class="workspace-overview__metric<?= $search === '' && $queue === $key ? ' is-active' : '' ?>"
               <?= $search === '' && $queue === $key ? 'aria-current="page"' : '' ?> href="?queue=<?= $key ?>">
                <span class="workspace-overview__metric-label"><?= e($queueLabels[$key]) ?></span>
                <strong class="workspace-overview__metric-value financial-number"><?= $work['counts'][$key] ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>
</section>

<div class="row g-3 align-items-start">
    <div class="col-12 col-xl-8">
        <section class="statistics-panel work-queue-panel" aria-labelledby="queue-list-title">
            <header class="statistics-panel__head align-items-center flex-wrap">
                <h2 id="queue-list-title"><?= e($search === '' ? $queueLabels[$queue] : __('queue_search')) ?>
                    <span class="statistics-panel__count financial-number"><?= $total ?></span>
                </h2>
                <?php if ($search !== '' || $queue !== 'attention'): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="index.php"><?= e($queueLabels['attention']) ?></a>
                <?php else: ?>
                    <a class="btn btn-outline-secondary btn-sm" href="orders.php"><?= e(__('all_orders')) ?></a>
                <?php endif; ?>
            </header>
            <?php if (!$rows): ?>
                <div class="empty-state"><span class="empty-state__mark" aria-hidden="true"></span>
                    <p><?= e(__($search !== '' ? 'no_orders_found' : ($queue === 'attention' ? 'queue_attention_empty' : 'queue_empty'))) ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-mobile-cards work-queue-table">
                        <thead><tr>
                            <th><?= e(__('order')) ?></th>
                            <th><?= e(__('client')) ?></th>
                            <th><?= e(__('status')) ?></th>
                            <?php if ($isAdmin): ?><th><?= e(__('technician')) ?></th><?php endif; ?>
                            <?php if ($queue === 'approval' || $search !== ''): ?><th><?= e(__('amount')) ?></th><?php endif; ?>
                            <?php if ($queue === 'ready' && $search === ''): ?><th><?= e(__('shipping_method')) ?></th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row):
                            $device = trim(($row['device_brand'] ?? '') . ' ' . ($row['device_model'] ?? ''));
                            $overdue = (int)($row['overdue_seconds'] ?? 0);
                        ?>
                            <tr<?= $row['priority'] === 'High' ? ' class="work-queue-priority"' : '' ?>>
                                <td class="order-list-id" data-label="<?= e(__('order')) ?>"><div>
                                    <a class="mobile-order-link fw-semibold" href="view_order.php?id=<?= (int)$row['id'] ?>">#<?= (int)$row['id'] ?></a>
                                    <small><?= e($device !== '' ? $device : __($row['device_type'])) ?></small>
                                </div></td>
                                <td data-label="<?= e(__('client')) ?>"><div>
                                    <?= e(crmCustomerDisplayName($row)) ?>
                                    <div><?= crmPhoneQrTrigger($row['phone'] ?? null, 'small text-white-75') ?></div>
                                </div></td>
                                <td class="order-list-status" data-label="<?= e(__('status')) ?>"><div>
                                    <?= getStatusBadge($row['status']) ?>
                                    <?php if ($search === ''): ?>
                                        <?php if ($overdue > 0): ?>
                                            <small class="queue-late"><?= e(sprintf(__('queue_late_by'), crmStatisticsDurationLabel($overdue))) ?></small>
                                        <?php endif; ?>
                                        <?php if (in_array($queue, ['parts', 'approval', 'ready'], true)): ?>
                                            <small><?= e(sprintf(__('queue_stage_age'), crmStatisticsDurationLabel(max(0, (int)$row['age_seconds'])))) ?></small>
                                        <?php endif; ?>
                                        <?php if ($row['priority'] === 'High'): ?><small><?= e(__('queue_priority')) ?></small><?php endif; ?>
                                    <?php endif; ?>
                                </div></td>
                                <?php if ($isAdmin): ?><td data-label="<?= e(__('technician')) ?>"><?= e($row['tech_name'] ?? '—') ?></td><?php endif; ?>
                                <?php if ($queue === 'approval' || $search !== ''):
                                    $amount = $row['final_cost'] ?? $row['estimated_cost'];
                                ?><td class="financial-number" data-label="<?= e(__('amount')) ?>"><?= $amount === null ? '—' : e(formatMoney($amount)) ?></td><?php endif; ?>
                                <?php if ($queue === 'ready' && $search === ''): ?>
                                    <td data-label="<?= e(__('shipping_method')) ?>"><?= e($row['shipping_method'] === 'Self Pickup' ? __('self_pickup') : ($row['shipping_method'] ?: '—')) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($search !== ''): ?>
                <div class="p-3"><a href="<?= e($searchUrl) ?>"><?= e(__('all_orders')) ?></a></div>
            <?php elseif ($work['pages'] > 1): ?>
                <nav class="d-flex align-items-center gap-3 p-3" aria-label="<?= e(__('customer_pages')) ?>">
                    <?php if ($work['page'] > 1): ?><a class="btn btn-outline-secondary btn-sm" rel="prev" href="?queue=<?= $queue ?>&amp;p=<?= $work['page'] - 1 ?>"><?= e(__('back')) ?></a><?php endif; ?>
                    <span><?= $work['page'] ?> / <?= $work['pages'] ?></span>
                    <?php if ($work['page'] < $work['pages']): ?><a class="btn btn-outline-secondary btn-sm" rel="next" href="?queue=<?= $queue ?>&amp;p=<?= $work['page'] + 1 ?>"><?= e(__('customer_next')) ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>

    <?php
    $month = crmStatisticsPeriodOptions()['month'];
    $finance = null;
    try {
        $finance = crmWorkQueueFinance($pdo, $month['start'], $month['end']);
    } catch (PDOException $error) {
        error_log('Dashboard monthly finances: ' . $error->getMessage());
    }
    $financeLabels = [
        'revenue' => __('revenue'),
        'parts_cost' => __('parts_costs'),
        'expenses' => __('extra_expenses'),
        'earnings' => __($isAdmin ? 'engineer_payouts' : 'queue_my_earnings'),
        'sc_income' => __('sc_income'),
    ];
    ?>
    <aside class="col-12 col-xl-4">
        <?php if ($isAdmin): ?>
            <section class="statistics-panel" aria-labelledby="queue-workload">
                <header class="statistics-panel__head"><h2 id="queue-workload"><?= e(__('queue_workload')) ?></h2></header>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 queue-workload-table">
                        <thead><tr><th><?= e(__('technician')) ?></th><th><?= e(__('in_progress')) ?></th><th><?= e(__('queue_parts_short')) ?></th><th><?= e(__('statistics_overdue')) ?></th></tr></thead>
                        <tbody>
                        <?php foreach (getActiveTechnicians() as $tech):
                            $load = $work['byTechnician'][(int)$tech['id']] ?? [];
                        ?>
                            <tr><th scope="row"><?= e($tech['name']) ?></th><td><?= (int)($load['in_work'] ?? 0) ?></td><td><?= (int)($load['parts'] ?? 0) ?></td><td><?= (int)($load['overdue'] ?? 0) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <section class="statistics-panel<?= $isAdmin ? ' mt-3' : '' ?>" aria-labelledby="queue-finance">
            <header class="statistics-panel__head align-items-center flex-wrap">
                <h2 id="queue-finance"><?= e(__($isAdmin ? 'queue_finance_workshop' : 'queue_finance_personal')) ?></h2>
                <?php if ($isAdmin):
                    $reportUrl = 'reports.php?' . http_build_query(['tab' => 'staff_stats', 'start_date' => $month['start'], 'end_date' => $month['end']]);
                ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= e($reportUrl) ?>"><?= e(__('reports')) ?></a>
                <?php endif; ?>
            </header>
            <?php if ($finance === null): ?>
                <p class="p-3 mb-0 text-white-75"><?= e(__('queue_finance_unavailable')) ?></p>
            <?php else: ?>
                <dl class="list-group list-group-flush mb-0">
                    <?php foreach ($finance as $metric => $amount): ?>
                        <div class="list-group-item d-flex align-items-baseline justify-content-between gap-2">
                            <dt class="fw-normal"><?= e($financeLabels[$metric]) ?></dt>
                            <dd class="mb-0 text-end financial-number fw-semibold text-nowrap"><?= e(formatMoney($amount)) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php require 'includes/footer.php';
