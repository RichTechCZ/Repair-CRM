<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/statistics_dashboard.php';

// Statistics is available to system admins and to technician-scoped users.
// The service resolves the final technician scope from the session again.
if (!hasPermission('admin_access') && !isTechnicianScoped()) {
    header('Location: index.php');
    exit;
}

$scope = crmStatisticsScope();
$range = crmStatisticsResolveRange(
    isset($_GET['period']) ? (string)$_GET['period'] : 'week',
    isset($_GET['start_date']) ? (string)$_GET['start_date'] : null,
    isset($_GET['end_date']) ? (string)$_GET['end_date'] : null
);
$slaNewHours = max(0, (int)get_setting('sla_new_hours', 24));
$slaProgressHours = max(0, (int)get_setting('sla_progress_hours', 72));
$statistics = null;
$statisticsError = '';

try {
    $statistics = crmGetDashboardStatistics(
        $pdo,
        $range['start'],
        $range['end'],
        $slaNewHours,
        $slaProgressHours
    );
} catch (Throwable $e) {
    error_log('statistics.php query error: ' . $e->getMessage());
    $statisticsError = publicExceptionMessage($e);
}

require_once 'includes/header.php';

$summary = $statistics['summary'] ?? [];
$financial = $statistics['financial'] ?? [];
$periodLabel = $range['key'] === 'custom'
    ? __('statistics_period_custom')
    : ($range['options'][$range['key']]['label'] ?? __('statistics_period_week'));
$money = static function ($value): string {
    return $value === null ? '—' : formatMoney((float)$value);
};
$percent = static function ($value): string {
    return $value === null ? '—' : number_format((float)$value, 1, '.', ' ') . '%';
};
$dateTime = static function ($value): string {
    $timestamp = $value ? strtotime((string)$value) : false;
    return $timestamp ? date('d.m.Y H:i', $timestamp) : '—';
};
$statisticsUrl = static function (array $params): string {
    return 'statistics.php?' . http_build_query($params);
};

$timeline = $statistics['timeline'] ?? [];
$timelineMax = 1;
foreach ($timeline as $timelineRow) {
    $timelineMax = max(
        $timelineMax,
        (int)$timelineRow['received'],
        (int)$timelineRow['completed'],
        (int)$timelineRow['issued']
    );
}

$deviceRows = $statistics['by_device'] ?? [];
uasort($deviceRows, static function (array $left, array $right): int {
    return ($right['received'] <=> $left['received']) ?: strcmp((string)$left['device_type'], (string)$right['device_type']);
});

$summaryCards = [
    [
        'label' => __('statistics_received'),
        'value' => (int)($summary['received'] ?? 0),
        'note' => __('statistics_period_metric'),
        'tone' => 'warm',
    ],
    [
        'label' => __('statistics_repaired'),
        'value' => (int)($summary['repaired'] ?? 0),
        'note' => $percent($summary['success_rate'] ?? null) . ' ' . __('statistics_success_rate_short'),
        'tone' => 'success',
    ],
    [
        'label' => __('statistics_issued'),
        'value' => (int)($summary['issued'] ?? 0),
        'note' => __('statistics_period_metric'),
        'tone' => 'neutral',
    ],
    [
        'label' => __('statistics_in_progress_now'),
        'value' => (int)($summary['current_in_progress'] ?? 0),
        'note' => __('statistics_current_snapshot'),
        'tone' => 'info',
    ],
    [
        'label' => __('statistics_ready_now'),
        'value' => (int)($summary['current_ready'] ?? 0),
        'note' => __('statistics_current_snapshot'),
        'tone' => 'success',
    ],
    [
        'label' => __('statistics_overdue'),
        'value' => (int)($summary['overdue'] ?? 0),
        'note' => __('statistics_sla_snapshot'),
        'tone' => 'danger',
    ],
];
?>

<div class="statistics-page">
    <div class="page-header statistics-page__header">
        <div class="page-header__copy">
            <div class="page-kicker"><?php echo e(get_setting('company_name', 'Repair CRM')); ?></div>
            <h1><?php echo e(__('statistics')); ?></h1>
            <p class="page-subtitle">
                <?php echo e($periodLabel); ?> · <?php echo e($range['start']); ?> — <?php echo e($range['end']); ?>
            </p>
        </div>
        <div class="statistics-page__actions">
            <nav class="statistics-periods" aria-label="<?php echo e(__('statistics_periods')); ?>">
                <?php foreach ($range['options'] as $periodKey => $period): ?>
                    <a class="statistics-periods__link <?php echo $range['key'] === $periodKey ? 'is-active' : ''; ?>"
                       href="<?php echo e($statisticsUrl(['period' => $periodKey])); ?>">
                        <?php echo e($period['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="get" class="statistics-custom-range">
                <input type="hidden" name="period" value="custom">
                <label class="visually-hidden" for="statisticsStartDate"><?php echo e(__('statistics_start_date')); ?></label>
                <input id="statisticsStartDate" type="date" name="start_date" class="form-control form-control-sm" value="<?php echo e($range['key'] === 'custom' ? $range['start'] : ''); ?>">
                <span aria-hidden="true">—</span>
                <label class="visually-hidden" for="statisticsEndDate"><?php echo e(__('statistics_end_date')); ?></label>
                <input id="statisticsEndDate" type="date" name="end_date" class="form-control form-control-sm" value="<?php echo e($range['key'] === 'custom' ? $range['end'] : ''); ?>">
                <button type="submit" class="btn btn-sm btn-primary"><?php echo e(__('statistics_apply')); ?></button>
            </form>
        </div>
    </div>

    <div class="statistics-scope-note" role="status">
        <span class="statistics-scope-note__mark" aria-hidden="true"></span>
        <?php if ($scope['is_admin']): ?>
            <?php echo e(__('statistics_scope_all')); ?>
        <?php else: ?>
            <?php echo e(__('statistics_scope_personal')); ?>
        <?php endif; ?>
    </div>

    <?php if ($statisticsError !== ''): ?>
        <div class="alert alert-danger" role="alert">
            <?php echo e($statisticsError); ?>
        </div>
    <?php else: ?>
        <section class="statistics-kpi-grid" aria-label="<?php echo e(__('statistics_key_metrics')); ?>">
            <?php foreach ($summaryCards as $card): ?>
                <article class="statistics-kpi statistics-kpi--<?php echo e($card['tone']); ?>">
                    <div class="statistics-kpi__label"><?php echo e($card['label']); ?></div>
                    <div class="statistics-kpi__value financial-number"><?php echo e((string)$card['value']); ?></div>
                    <div class="statistics-kpi__note"><?php echo e($card['note']); ?></div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="statistics-finance-grid" aria-label="<?php echo e(__('statistics_finance')); ?>">
            <article class="statistics-finance-card">
                <div class="statistics-finance-card__label"><?php echo e(__('statistics_revenue')); ?></div>
                <div class="statistics-finance-card__value financial-number"><?php echo e($money($financial['revenue'] ?? null)); ?></div>
                <div class="statistics-finance-card__note"><?php echo e(__('statistics_realized_orders')); ?></div>
            </article>
            <?php if ($scope['is_admin']): ?>
                <article class="statistics-finance-card">
                    <div class="statistics-finance-card__label"><?php echo e(__('statistics_net_profit')); ?></div>
                    <div class="statistics-finance-card__value financial-number"><?php echo e($money($financial['net_profit'] ?? null)); ?></div>
                    <div class="statistics-finance-card__note"><?php echo e(__('statistics_finance_formula')); ?></div>
                </article>
            <?php else: ?>
                <article class="statistics-finance-card">
                    <div class="statistics-finance-card__label"><?php echo e(__('statistics_engineer_earnings')); ?></div>
                    <div class="statistics-finance-card__value financial-number"><?php echo e($money($financial['earnings'] ?? null)); ?></div>
                    <div class="statistics-finance-card__note"><?php echo e(__('statistics_personal_only')); ?></div>
                </article>
            <?php endif; ?>
            <article class="statistics-finance-card">
                <div class="statistics-finance-card__label"><?php echo e(__('statistics_average_check')); ?></div>
                <div class="statistics-finance-card__value financial-number"><?php echo e($money($financial['average_check'] ?? null)); ?></div>
                <div class="statistics-finance-card__note"><?php echo e(__('statistics_realized_orders')); ?></div>
            </article>
            <article class="statistics-finance-card">
                <div class="statistics-finance-card__label"><?php echo e(__('statistics_parts_cost')); ?></div>
                <div class="statistics-finance-card__value financial-number"><?php echo e($money($financial['parts_cost'] ?? null)); ?></div>
                <div class="statistics-finance-card__note"><?php echo e(__('statistics_purchase_cost')); ?></div>
            </article>
        </section>

        <div class="statistics-layout statistics-layout--primary">
            <section class="statistics-panel statistics-panel--timeline" aria-labelledby="statisticsTimelineTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_dynamics')); ?></div>
                        <h2 id="statisticsTimelineTitle"><?php echo e(__('statistics_period_dynamics')); ?></h2>
                    </div>
                    <div class="statistics-legend" aria-label="<?php echo e(__('statistics_legend')); ?>">
                        <span><i class="statistics-legend__dot statistics-legend__dot--received" aria-hidden="true"></i><?php echo e(__('statistics_received')); ?></span>
                        <span><i class="statistics-legend__dot statistics-legend__dot--completed" aria-hidden="true"></i><?php echo e(__('statistics_repaired')); ?></span>
                        <span><i class="statistics-legend__dot statistics-legend__dot--issued" aria-hidden="true"></i><?php echo e(__('statistics_issued')); ?></span>
                    </div>
                </div>
                <?php if ($timeline === []): ?>
                    <div class="statistics-empty"><?php echo e(__('statistics_no_data')); ?></div>
                <?php else: ?>
                    <div class="statistics-timeline">
                        <?php foreach ($timeline as $timelineRow): ?>
                            <div class="statistics-timeline__row">
                                <div class="statistics-timeline__label"><?php echo e($timelineRow['bucket']); ?></div>
                                <div class="statistics-timeline__bars">
                                    <?php foreach ([
                                        ['key' => 'received', 'class' => 'received'],
                                        ['key' => 'completed', 'class' => 'completed'],
                                        ['key' => 'issued', 'class' => 'issued'],
                                    ] as $bar): ?>
                                        <?php $barValue = (int)$timelineRow[$bar['key']]; ?>
                                        <div class="statistics-timeline__bar-line">
                                            <span class="statistics-timeline__bar statistics-timeline__bar--<?php echo e($bar['class']); ?>"
                                                  style="width: <?php echo $barValue > 0 ? max(3, round(($barValue / $timelineMax) * 100, 1)) : 0; ?>%"
                                                  title="<?php echo e((string)$barValue); ?>"></span>
                                            <span class="statistics-timeline__bar-value"><?php echo e((string)$barValue); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="statistics-panel" aria-labelledby="statisticsStatusesTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_current_snapshot')); ?></div>
                        <h2 id="statisticsStatusesTitle"><?php echo e(__('statistics_status_board')); ?></h2>
                    </div>
                </div>
                <div class="statistics-status-list">
                    <?php foreach (($statistics['status_board'] ?? []) as $statusRow): ?>
                        <div class="statistics-status-row">
                            <div><?php echo getStatusBadge($statusRow['key']); ?></div>
                            <strong class="financial-number"><?php echo e((string)$statusRow['count']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="statistics-sla-note">
                    <?php echo e(sprintf(__('statistics_sla_note'), $slaNewHours, $slaProgressHours)); ?>
                </div>
            </section>
        </div>

        <div class="statistics-layout statistics-layout--secondary">
            <section class="statistics-panel" aria-labelledby="statisticsDeviceTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_breakdown')); ?></div>
                        <h2 id="statisticsDeviceTitle"><?php echo e(__('statistics_by_device')); ?></h2>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle statistics-table table-mobile-cards">
                        <thead>
                            <tr>
                                <th><?php echo e(__('device_type')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_received_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_repaired_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_success_rate_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_average_time')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_average_check')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_warranty_short')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deviceRows === []): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><?php echo e(__('statistics_no_data')); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($deviceRows as $deviceType => $deviceRow): ?>
                                    <?php
                                    $deviceFinance = $statistics['by_device_finance'][$deviceType] ?? [];
                                    $deviceAverageCheck = (int)($deviceFinance['finance_orders'] ?? 0) > 0
                                        ? (float)$deviceFinance['revenue'] / (int)$deviceFinance['finance_orders']
                                        : null;
                                    ?>
                                    <tr>
                                        <td data-label="<?php echo e(__('device_type')); ?>">
                                            <?php echo getDeviceIcon($deviceType); ?>
                                            <strong><?php echo e(__($deviceType)); ?></strong>
                                        </td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_received_short')); ?>"><?php echo e((string)$deviceRow['received']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_repaired_short')); ?>"><?php echo e((string)$deviceRow['completed']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_success_rate_short')); ?>"><?php echo e($percent($deviceRow['success_rate'])); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_average_time')); ?>"><?php echo e(crmStatisticsDurationLabel($deviceRow['average_repair_seconds'])); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_average_check')); ?>"><?php echo e($money($deviceAverageCheck)); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_warranty_short')); ?>"><?php echo e((string)$deviceRow['warranty']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="statistics-panel" aria-labelledby="statisticsOverdueTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_sla')); ?></div>
                        <h2 id="statisticsOverdueTitle"><?php echo e(__('statistics_overdue_orders')); ?></h2>
                    </div>
                    <span class="statistics-panel__count financial-number"><?php echo e((string)($summary['overdue'] ?? 0)); ?></span>
                </div>
                <?php if (empty($statistics['overdue_orders'])): ?>
                    <div class="statistics-empty"><?php echo e(__('statistics_no_overdue')); ?></div>
                <?php else: ?>
                    <div class="statistics-overdue-list">
                        <?php foreach ($statistics['overdue_orders'] as $overdueOrder): ?>
                            <a class="statistics-overdue-row" href="view_order.php?id=<?php echo (int)$overdueOrder['id']; ?>">
                                <span class="statistics-overdue-row__id">#<?php echo (int)$overdueOrder['id']; ?></span>
                                <span class="statistics-overdue-row__device"><?php echo e(__((string)$overdueOrder['device_type'])); ?></span>
                                <span><?php echo getStatusBadge($overdueOrder['status']); ?></span>
                                <span class="statistics-overdue-row__age"><?php echo e(crmStatisticsDurationLabel($overdueOrder['overdue_seconds'])); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <?php if ($scope['is_admin']): ?>
            <section class="statistics-panel" aria-labelledby="statisticsTechniciansTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_breakdown')); ?></div>
                        <h2 id="statisticsTechniciansTitle"><?php echo e(__('statistics_by_technician')); ?></h2>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle statistics-table table-mobile-cards">
                        <thead>
                            <tr>
                                <th><?php echo e(__('technician')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_assigned_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_repaired_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_in_work_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_overdue')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_average_time')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_success_rate_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_warranty_short')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_revenue')); ?></th>
                                <th class="text-end"><?php echo e(__('statistics_earnings_short')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($statistics['technicians'])): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4"><?php echo e(__('statistics_no_data')); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($statistics['technicians'] as $technician): ?>
                                    <tr>
                                        <td data-label="<?php echo e(__('technician')); ?>"><strong><?php echo e($technician['name']); ?></strong></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_assigned_short')); ?>"><?php echo e((string)$technician['assigned']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_repaired_short')); ?>"><?php echo e((string)$technician['completed']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_in_work_short')); ?>"><?php echo e((string)$technician['in_progress']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_overdue')); ?>"><?php echo e((string)$technician['overdue']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_average_time')); ?>"><?php echo e(crmStatisticsDurationLabel($technician['average_repair_seconds'])); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_success_rate_short')); ?>"><?php echo e($percent($technician['success_rate'])); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_warranty_short')); ?>"><?php echo e((string)$technician['warranty']); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_revenue')); ?>"><?php echo e($money($technician['revenue'])); ?></td>
                                        <td class="text-end" data-label="<?php echo e(__('statistics_earnings_short')); ?>"><?php echo e($money($technician['earnings'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!empty($statistics['reasons'])): ?>
            <section class="statistics-panel statistics-panel--reasons" aria-labelledby="statisticsReasonsTitle">
                <div class="statistics-panel__head">
                    <div>
                        <div class="section-kicker"><?php echo e(__('statistics_quality')); ?></div>
                        <h2 id="statisticsReasonsTitle"><?php echo e(__('statistics_refusal_reasons')); ?></h2>
                    </div>
                </div>
                <div class="statistics-reasons">
                    <?php foreach ($statistics['reasons'] as $reason): ?>
                        <div class="statistics-reason-row">
                            <span><?php echo e($reason['reason']); ?></span>
                            <strong class="financial-number"><?php echo e((string)$reason['count']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
