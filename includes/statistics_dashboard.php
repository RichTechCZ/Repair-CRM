<?php
/**
 * Isolated operational statistics service for statistics.php.
 *
 * This module is read-only. It never changes CRM data or schema and derives
 * lifecycle metrics from order_status_log rather than orders.updated_at.
 */

require_once __DIR__ . '/reports_stats.php';

/**
 * Return the bounded quick periods used by the statistics dashboard.
 * The end of every preset is the current day so future-dated rows are not
 * accidentally included in a live dashboard.
 *
 * @return array<string,array{label:string,start:string,end:string}>
 */
function crmStatisticsPeriodOptions(?DateTimeImmutable $today = null): array
{
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0, 0);

    $periods = [
        'week' => [
            'label' => function_exists('__') ? __('statistics_period_week') : 'Week',
            'start' => $today->modify('monday this week'),
        ],
        'month' => [
            'label' => function_exists('__') ? __('statistics_period_month') : 'Month',
            'start' => $today->modify('first day of this month'),
        ],
        'six_months' => [
            'label' => function_exists('__') ? __('statistics_period_six_months') : 'Last 6 months',
            'start' => $today->modify('-5 months')->modify('first day of this month'),
        ],
        'year' => [
            'label' => function_exists('__') ? __('statistics_period_year') : 'Year',
            'start' => $today->modify('first day of January'),
        ],
    ];

    foreach ($periods as $key => $period) {
        $periods[$key]['start'] = $period['start']->format('Y-m-d');
        $periods[$key]['end'] = $today->format('Y-m-d');
    }

    return $periods;
}

function crmStatisticsParseDate(?string $value): ?DateTimeImmutable
{
    $value = (string)$value;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }

    return $date;
}

/**
 * Resolve a quick period or a bounded custom date range.
 *
 * @return array{key:string,start:string,end:string,options:array}
 */
function crmStatisticsResolveRange(
    ?string $period,
    ?string $startDate = null,
    ?string $endDate = null,
    ?DateTimeImmutable $today = null
): array {
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0, 0);
    $options = crmStatisticsPeriodOptions($today);
    $period = (string)$period;

    if ($period === 'custom') {
        $customStart = crmStatisticsParseDate($startDate);
        $customEnd = crmStatisticsParseDate($endDate);
        if (
            $customStart !== null
            && $customEnd !== null
            && $customStart <= $customEnd
            && $customEnd <= $today
            && $customStart->diff($customEnd)->days <= 366
        ) {
            return [
                'key' => 'custom',
                'start' => $customStart->format('Y-m-d'),
                'end' => $customEnd->format('Y-m-d'),
                'options' => $options,
            ];
        }
    }

    if (!isset($options[$period])) {
        $period = 'week';
    }

    return [
        'key' => $period,
        'start' => $options[$period]['start'],
        'end' => $options[$period]['end'],
        'options' => $options,
    ];
}

/**
 * Resolve the reporting scope exclusively from the authenticated session.
 * No dashboard request parameter can select another technician.
 *
 * @return array{is_admin:bool,technician_id:?int}
 */
function crmStatisticsScope(): array
{
    if (function_exists('hasPermission') && hasPermission('admin_access')) {
        return ['is_admin' => true, 'technician_id' => null];
    }

    if (function_exists('isTechnicianScoped') && isTechnicianScoped()) {
        $technicianId = function_exists('currentTechnicianId')
            ? currentTechnicianId()
            : null;
        if ($technicianId !== null && $technicianId > 0) {
            return ['is_admin' => false, 'technician_id' => $technicianId];
        }
    }

    throw new RuntimeException('Statistics access denied.');
}

/**
 * Build the only technician filter used by this module.
 *
 * @param array<int,mixed> $params
 */
function crmStatisticsScopeCondition(?int $technicianId, array &$params): string
{
    if ($technicianId === null) {
        return '';
    }

    $params[] = $technicianId;
    return 'o.technician_id = ?';
}

/**
 * Status values stay explicit here so legacy rows can still be reported while
 * the current 8-status schema remains the source of truth.
 *
 * @return array<int,array{key:string,label:string,values:array<int,string>}>
 */
function crmStatisticsStatusDefinitions(): array
{
    $translate = static function (string $key, string $fallback): string {
        return function_exists('__') ? (string)__($key) : $fallback;
    };

    return [
        ['key' => 'Accepted', 'label' => $translate('status_accepted', 'Accepted'), 'values' => ['Accepted', 'New']],
        ['key' => 'Diagnostics', 'label' => $translate('status_diagnostics', 'Diagnostics'), 'values' => ['Diagnostics']],
        ['key' => 'Approval', 'label' => $translate('status_approval', 'Approval'), 'values' => ['Approval', 'Pending Approval']],
        ['key' => 'In Repair', 'label' => $translate('status_in_repair', 'In repair'), 'values' => ['In Repair', 'In Progress']],
        ['key' => 'Waiting for Parts', 'label' => $translate('waiting_parts', 'Waiting for parts'), 'values' => ['Waiting for Parts']],
        ['key' => 'Ready', 'label' => $translate('status_ready', 'Ready'), 'values' => ['Ready', 'Completed']],
        ['key' => 'Issued', 'label' => $translate('status_issued', 'Issued'), 'values' => ['Issued', 'Collected']],
        [
            'key' => 'Issued Without Repair',
            'label' => $translate('status_issued_without_repair', 'Issued without repair'),
            'values' => ['Issued Without Repair'],
        ],
        [
            'key' => 'Repair Cancelled',
            'label' => $translate('status_repair_cancelled', 'Repair cancelled'),
            'values' => ['Repair Cancelled', 'Cancelled'],
        ],
    ];
}

/** @param array<int,string> $values */
function crmStatisticsInClause(array $values, array &$params): string
{
    $values = array_values(array_unique(array_filter(array_map('strval', $values), static function ($value) {
        return $value !== '';
    })));
    if ($values === []) {
        return 'NULL';
    }

    $params = array_merge($params, $values);
    return implode(',', array_fill(0, count($values), '?'));
}

/**
 * One row per order with the latest relevant lifecycle transition dates.
 * The current-status timestamp is the latest status-log event, which is also
 * the row updated by the existing manual Status date editor.
 */
function crmStatisticsStatusDatesSql(): string
{
    return "
        SELECT
            order_id,
            MAX(CASE WHEN new_status IN ('Accepted','New') THEN changed_at END) AS accepted_at,
            MAX(CASE WHEN new_status IN ('Diagnostics') THEN changed_at END) AS diagnostics_at,
            MAX(CASE WHEN new_status IN ('Approval','Pending Approval') THEN changed_at END) AS approval_at,
            MAX(CASE WHEN new_status IN ('In Repair','In Progress') THEN changed_at END) AS in_progress_at,
            MAX(CASE WHEN new_status IN ('Waiting for Parts') THEN changed_at END) AS waiting_parts_at,
            MAX(CASE WHEN new_status IN ('Ready','Completed') THEN changed_at END) AS completed_at,
            MAX(CASE WHEN new_status IN ('Issued','Collected') THEN changed_at END) AS issued_at,
            MAX(CASE WHEN new_status IN ('Issued Without Repair','Repair Cancelled','Cancelled') THEN changed_at END) AS cancelled_at,
            MAX(changed_at) AS status_since
        FROM order_status_log
        GROUP BY order_id
    ";
}

function crmStatisticsDateWindowStart(string $date): string
{
    return $date . ' 00:00:00';
}

function crmStatisticsDateWindowEnd(string $date): string
{
    return $date . ' 23:59:59';
}

function crmStatisticsDurationLabel(?float $seconds): string
{
    if ($seconds === null || !is_finite($seconds) || $seconds < 0) {
        return '—';
    }

    $hours = $seconds / 3600;
    if ($hours < 48) {
        $unit = function_exists('__') ? __('statistics_hours_short') : 'h';
        return number_format($hours, 1, '.', ' ') . ' ' . $unit;
    }

    $days = $hours / 24;
    $unit = function_exists('__') ? __('statistics_days_short') : 'd';
    return number_format($days, 1, '.', ' ') . ' ' . $unit;
}

/** @param array<string,mixed> $stats */
function crmStatisticsRate($numerator, $denominator): ?float
{
    $denominator = (int)$denominator;
    if ($denominator <= 0) {
        return null;
    }

    return round(((float)$numerator / $denominator) * 100, 1);
}

/**
 * Read all data required by the isolated dashboard.
 *
 * @return array<string,mixed>
 */
function crmGetDashboardStatistics(
    PDO $pdo,
    string $start,
    string $end,
    int $slaNewHours = 24,
    int $slaProgressHours = 72
): array {
    $scope = crmStatisticsScope();
    $technicianId = $scope['technician_id'];
    $startAt = crmStatisticsDateWindowStart($start);
    $endAt = crmStatisticsDateWindowEnd($end);

    $emptyDimension = static function (): array {
        return [
            'received' => 0,
            'completed' => 0,
            'warranty' => 0,
            'repair_seconds' => 0.0,
            'repair_time_count' => 0,
            'success_rate' => null,
            'average_repair_seconds' => null,
        ];
    };

    // Current status snapshot. This deliberately does not use a date filter:
    // the current workload should include an older order that is still open.
    $currentParams = [];
    $currentScope = crmStatisticsScopeCondition($technicianId, $currentParams);
    $currentSql = "SELECT o.status, o.technician_id, COUNT(*) AS order_count\n"
        . 'FROM orders o'
        . ($currentScope !== '' ? ' WHERE ' . $currentScope : '')
        . ' GROUP BY o.status, o.technician_id';
    $currentStmt = $pdo->prepare($currentSql);
    $currentStmt->execute($currentParams);

    $currentByStatus = [];
    $currentByTechnician = [];
    foreach ($currentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['status'] ?? '');
        $count = (int)($row['order_count'] ?? 0);
        $currentByStatus[$status] = ($currentByStatus[$status] ?? 0) + $count;

        $rowTechnicianId = $row['technician_id'] === null ? 0 : (int)$row['technician_id'];
        if (!isset($currentByTechnician[$rowTechnicianId])) {
            $currentByTechnician[$rowTechnicianId] = [];
        }
        $currentByTechnician[$rowTechnicianId][$status] = $count;
    }

    // Period operations use status transitions, not mutable order edits.
    $summaryParams = [
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
    ];
    $summaryScopeParams = [];
    $summaryScope = crmStatisticsScopeCondition($technicianId, $summaryScopeParams);
    $summarySql = "
        SELECT
            SUM(CASE WHEN o.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS received,
            SUM(CASE WHEN status_dates.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS repaired,
            SUM(CASE WHEN status_dates.issued_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS issued,
            SUM(CASE WHEN status_dates.cancelled_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN o.order_type = 'Warranty' AND o.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS warranty,
            SUM(CASE
                WHEN status_dates.completed_at BETWEEN ? AND ?
                 AND status_dates.completed_at >= o.created_at
                THEN TIMESTAMPDIFF(SECOND, o.created_at, status_dates.completed_at)
                ELSE 0
            END) AS repair_seconds,
            SUM(CASE
                WHEN status_dates.completed_at BETWEEN ? AND ?
                 AND status_dates.completed_at >= o.created_at
                THEN 1 ELSE 0
            END) AS repair_time_count
        FROM orders o
        LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
            ON status_dates.order_id = o.id
    " . ($summaryScope !== '' ? ' WHERE ' . $summaryScope : '');
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute(array_merge($summaryParams, $summaryScopeParams));
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // One grouped query feeds both the device and technician breakdowns.
    $dimensionParams = [
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
    ];
    $dimensionScopeParams = [];
    $dimensionScope = crmStatisticsScopeCondition($technicianId, $dimensionScopeParams);
    $dimensionSql = "
        SELECT
            COALESCE(NULLIF(TRIM(o.device_type), ''), 'Other') AS device_type,
            o.technician_id,
            SUM(CASE WHEN o.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS received,
            SUM(CASE WHEN status_dates.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN o.order_type = 'Warranty' AND o.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS warranty,
            SUM(CASE
                WHEN status_dates.completed_at BETWEEN ? AND ?
                 AND status_dates.completed_at >= o.created_at
                THEN TIMESTAMPDIFF(SECOND, o.created_at, status_dates.completed_at)
                ELSE 0
            END) AS repair_seconds,
            SUM(CASE
                WHEN status_dates.completed_at BETWEEN ? AND ?
                 AND status_dates.completed_at >= o.created_at
                THEN 1 ELSE 0
            END) AS repair_time_count
        FROM orders o
        LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
            ON status_dates.order_id = o.id
    " . ($dimensionScope !== '' ? ' WHERE ' . $dimensionScope : '') . "
        GROUP BY COALESCE(NULLIF(TRIM(o.device_type), ''), 'Other'), o.technician_id
        ORDER BY received DESC, device_type ASC
    ";
    $dimensionStmt = $pdo->prepare($dimensionSql);
    $dimensionStmt->execute(array_merge($dimensionParams, $dimensionScopeParams));

    $byDevice = [];
    $byTechnician = [];
    foreach ($dimensionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deviceType = (string)$row['device_type'];
        $rowTechnicianId = $row['technician_id'] === null ? 0 : (int)$row['technician_id'];
        $metrics = [
            'received' => (int)($row['received'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'warranty' => (int)($row['warranty'] ?? 0),
            'repair_seconds' => (float)($row['repair_seconds'] ?? 0),
            'repair_time_count' => (int)($row['repair_time_count'] ?? 0),
        ];

        if (
            $metrics['received'] === 0
            && $metrics['completed'] === 0
            && $metrics['warranty'] === 0
            && $metrics['repair_time_count'] === 0
        ) {
            continue;
        }

        if (!isset($byDevice[$deviceType])) {
            $byDevice[$deviceType] = $emptyDimension();
        }
        foreach ($metrics as $metric => $value) {
            $byDevice[$deviceType][$metric] += $value;
        }

        if (!isset($byTechnician[$rowTechnicianId])) {
            $byTechnician[$rowTechnicianId] = $emptyDimension();
        }
        foreach ($metrics as $metric => $value) {
            $byTechnician[$rowTechnicianId][$metric] += $value;
        }
    }

    foreach ($byDevice as $deviceType => $metrics) {
        $byDevice[$deviceType]['success_rate'] = crmStatisticsRate($metrics['completed'], $metrics['received']);
        $byDevice[$deviceType]['average_repair_seconds'] = $metrics['repair_time_count'] > 0
            ? $metrics['repair_seconds'] / $metrics['repair_time_count']
            : null;
    }
    foreach ($byTechnician as $rowTechnicianId => $metrics) {
        $byTechnician[$rowTechnicianId]['success_rate'] = crmStatisticsRate($metrics['completed'], $metrics['received']);
        $byTechnician[$rowTechnicianId]['average_repair_seconds'] = $metrics['repair_time_count'] > 0
            ? $metrics['repair_seconds'] / $metrics['repair_time_count']
            : null;
    }

    $financeBatch = getDetailedStatsBatch($pdo, $start, $end, $technicianId);
    $financial = $scope['is_admin']
        ? ($financeBatch['all'] ?? crmEmptyDetailedStats())
        : ($financeBatch['by_technician'][$technicianId] ?? crmEmptyDetailedStats());
    $financial['average_check'] = (int)($financial['finance_orders'] ?? 0) > 0
        ? (float)$financial['revenue'] / (int)$financial['finance_orders']
        : null;

    // Period timeline: daily for short ranges, monthly for longer ranges.
    $startObject = crmStatisticsParseDate($start);
    $endObject = crmStatisticsParseDate($end);
    $rangeDays = ($startObject && $endObject) ? $startObject->diff($endObject)->days : 0;
    $bucketExpression = $rangeDays <= 45
        ? "DATE_FORMAT(o.created_at, '%Y-%m-%d')"
        : "DATE_FORMAT(o.created_at, '%Y-%m')";
    $completedBucketExpression = $rangeDays <= 45
        ? "DATE_FORMAT(status_dates.completed_at, '%Y-%m-%d')"
        : "DATE_FORMAT(status_dates.completed_at, '%Y-%m')";
    $issuedBucketExpression = $rangeDays <= 45
        ? "DATE_FORMAT(status_dates.issued_at, '%Y-%m-%d')"
        : "DATE_FORMAT(status_dates.issued_at, '%Y-%m')";

    $receivedTimelineParams = [$startAt, $endAt];
    $receivedTimelineScope = crmStatisticsScopeCondition($technicianId, $receivedTimelineParams);
    $completedTimelineParams = [$startAt, $endAt];
    $completedTimelineScope = crmStatisticsScopeCondition($technicianId, $completedTimelineParams);
    $issuedTimelineParams = [$startAt, $endAt];
    $issuedTimelineScope = crmStatisticsScopeCondition($technicianId, $issuedTimelineParams);
    $timelineSql = "
        SELECT bucket,
               SUM(received) AS received,
               SUM(completed) AS completed,
               SUM(issued) AS issued
        FROM (
            SELECT {$bucketExpression} AS bucket, 1 AS received, 0 AS completed, 0 AS issued
            FROM orders o
            WHERE o.created_at BETWEEN ? AND ?
            " . ($receivedTimelineScope !== '' ? ' AND ' . $receivedTimelineScope : '') . "
            UNION ALL
            SELECT {$completedBucketExpression} AS bucket, 0 AS received, 1 AS completed, 0 AS issued
            FROM orders o
            LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
                ON status_dates.order_id = o.id
            WHERE status_dates.completed_at BETWEEN ? AND ?
            " . ($completedTimelineScope !== '' ? ' AND ' . $completedTimelineScope : '') . "
            UNION ALL
            SELECT {$issuedBucketExpression} AS bucket, 0 AS received, 0 AS completed, 1 AS issued
            FROM orders o
            LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
                ON status_dates.order_id = o.id
            WHERE status_dates.issued_at BETWEEN ? AND ?
            " . ($issuedTimelineScope !== '' ? ' AND ' . $issuedTimelineScope : '') . "
        ) timeline_events
        GROUP BY bucket
        ORDER BY bucket ASC
    ";
    $timelineStmt = $pdo->prepare($timelineSql);
    $timelineStmt->execute(array_merge(
        $receivedTimelineParams,
        $completedTimelineParams,
        $issuedTimelineParams
    ));
    $timeline = [];
    foreach ($timelineStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $timeline[] = [
            'bucket' => (string)$row['bucket'],
            'received' => (int)($row['received'] ?? 0),
            'completed' => (int)($row['completed'] ?? 0),
            'issued' => (int)($row['issued'] ?? 0),
        ];
    }

    // SLA is an existing CRM setting. There is no separate due-date column.
    $slaNewSeconds = max(0, $slaNewHours) * 3600;
    $slaProgressSeconds = max(0, $slaProgressHours) * 3600;
    $openStatuses = ['Accepted', 'New', 'Diagnostics', 'In Repair', 'In Progress', 'Waiting for Parts'];
    $overdueByTechParams = [];
    $overdueStatusClause = crmStatisticsInClause($openStatuses, $overdueByTechParams);
    $overdueScope = crmStatisticsScopeCondition($technicianId, $overdueByTechParams);
    $ageExpression = 'TIMESTAMPDIFF(SECOND, COALESCE(status_dates.status_since, o.created_at), NOW())';
    $thresholdExpression = "CASE WHEN o.status IN ('Accepted','New') THEN {$slaNewSeconds} ELSE {$slaProgressSeconds} END";
    $overdueWhere = "o.status IN ({$overdueStatusClause}) AND {$ageExpression} > {$thresholdExpression}"
        . ($overdueScope !== '' ? ' AND ' . $overdueScope : '');
    $overdueCountSql = "
        SELECT o.technician_id, COUNT(*) AS count
        FROM orders o
        LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
            ON status_dates.order_id = o.id
        WHERE {$overdueWhere}
        GROUP BY o.technician_id
    ";
    $overdueCountStmt = $pdo->prepare($overdueCountSql);
    $overdueCountStmt->execute($overdueByTechParams);
    $overdueByTechnician = [];
    $overdueTotal = 0;
    foreach ($overdueCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowTechnicianId = $row['technician_id'] === null ? 0 : (int)$row['technician_id'];
        $count = (int)($row['count'] ?? 0);
        $overdueByTechnician[$rowTechnicianId] = $count;
        $overdueTotal += $count;
    }

    $overdueListParams = [];
    $overdueListStatusClause = crmStatisticsInClause($openStatuses, $overdueListParams);
    $overdueListScope = crmStatisticsScopeCondition($technicianId, $overdueListParams);
    $overdueListWhere = "o.status IN ({$overdueListStatusClause}) AND {$ageExpression} > {$thresholdExpression}"
        . ($overdueListScope !== '' ? ' AND ' . $overdueListScope : '');
    $overdueListSql = "
        SELECT o.id, o.device_type, o.status, o.technician_id, o.created_at,
               COALESCE(status_dates.status_since, o.created_at) AS status_since,
               {$ageExpression} - {$thresholdExpression} AS overdue_seconds
        FROM orders o
        LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
            ON status_dates.order_id = o.id
        WHERE {$overdueListWhere}
        ORDER BY status_since ASC, o.id ASC
        LIMIT 20
    ";
    $overdueListStmt = $pdo->prepare($overdueListSql);
    $overdueListStmt->execute($overdueListParams);
    $overdueOrders = [];
    foreach ($overdueListStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overdueOrders[] = [
            'id' => (int)$row['id'],
            'device_type' => (string)($row['device_type'] ?? 'Other'),
            'status' => (string)($row['status'] ?? ''),
            'technician_id' => $row['technician_id'] === null ? null : (int)$row['technician_id'],
            'status_since' => $row['status_since'] ?? null,
            'overdue_seconds' => (float)($row['overdue_seconds'] ?? 0),
        ];
    }

    $reasons = [];
    if (!function_exists('tableColumnExists') || tableColumnExists('orders', 'cancellation_reason')) {
        $reasonParams = [$startAt, $endAt];
        $reasonScope = crmStatisticsScopeCondition($technicianId, $reasonParams);
        $reasonSql = "
            SELECT TRIM(o.cancellation_reason) AS reason, COUNT(*) AS count
            FROM orders o
            LEFT JOIN (" . crmStatisticsStatusDatesSql() . ") status_dates
                ON status_dates.order_id = o.id
            WHERE status_dates.cancelled_at BETWEEN ? AND ?
              AND o.cancellation_reason IS NOT NULL
              AND TRIM(o.cancellation_reason) <> ''
            " . ($reasonScope !== '' ? ' AND ' . $reasonScope : '') . "
            GROUP BY TRIM(o.cancellation_reason)
            ORDER BY count DESC, reason ASC
            LIMIT 5
        ";
        $reasonStmt = $pdo->prepare($reasonSql);
        $reasonStmt->execute($reasonParams);
        foreach ($reasonStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reasons[] = [
                'reason' => (string)$row['reason'],
                'count' => (int)$row['count'],
            ];
        }
    }

    if ($scope['is_admin']) {
        $techStmt = $pdo->query('SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC');
        $technicianRows = $techStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $techStmt = $pdo->prepare('SELECT id, name FROM technicians WHERE id = ? AND is_active = 1 LIMIT 1');
        $techStmt->execute([$technicianId]);
        $technicianRows = $techStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $technicians = [];
    foreach ($technicianRows as $techRow) {
        $rowTechnicianId = (int)$techRow['id'];
        $periodMetrics = $byTechnician[$rowTechnicianId] ?? $emptyDimension();
        $currentMetrics = $currentByTechnician[$rowTechnicianId] ?? [];
        $financeMetrics = $financeBatch['by_technician'][$rowTechnicianId]
            ?? crmEmptyDetailedStats($financeBatch['rates'][$rowTechnicianId] ?? 50.0);
        $technicians[] = [
            'id' => $rowTechnicianId,
            'name' => (string)$techRow['name'],
            'assigned' => $periodMetrics['received'],
            'completed' => $periodMetrics['completed'],
            'in_progress' => array_sum(array_intersect_key(
                $currentMetrics,
                array_flip(['Diagnostics', 'In Repair', 'In Progress'])
            )),
            'waiting_parts' => (int)($currentMetrics['Waiting for Parts'] ?? 0),
            'overdue' => (int)($overdueByTechnician[$rowTechnicianId] ?? 0),
            'warranty' => $periodMetrics['warranty'],
            'average_repair_seconds' => $periodMetrics['average_repair_seconds'],
            'success_rate' => $periodMetrics['success_rate'],
            'revenue' => (float)($financeMetrics['revenue'] ?? 0),
            'earnings' => (float)($financeMetrics['earnings'] ?? 0),
            'parts_cost' => (float)($financeMetrics['parts_cost'] ?? 0),
            'expenses' => (float)($financeMetrics['expenses'] ?? 0),
            'net_profit' => (float)($financeMetrics['net_profit'] ?? 0),
            'sc_income' => (float)($financeMetrics['sc_income'] ?? 0),
            'engineer_rate' => (float)($financeMetrics['engineer_rate'] ?? 50.0),
        ];
    }

    $statusBoard = [];
    foreach (crmStatisticsStatusDefinitions() as $definition) {
        $count = 0;
        foreach ($definition['values'] as $statusValue) {
            $count += (int)($currentByStatus[$statusValue] ?? 0);
        }
        $statusBoard[] = [
            'key' => $definition['key'],
            'label' => $definition['label'],
            'count' => $count,
        ];
    }

    $currentInProgress = 0;
    foreach (['Diagnostics', 'In Repair', 'In Progress'] as $statusValue) {
        $currentInProgress += (int)($currentByStatus[$statusValue] ?? 0);
    }
    $currentWaitingParts = (int)($currentByStatus['Waiting for Parts'] ?? 0);
    $currentReady = (int)($currentByStatus['Ready'] ?? 0) + (int)($currentByStatus['Completed'] ?? 0);
    $currentCancelled = 0;
    foreach (['Issued Without Repair', 'Repair Cancelled', 'Cancelled'] as $statusValue) {
        $currentCancelled += (int)($currentByStatus[$statusValue] ?? 0);
    }

    $summary = [
        'received' => (int)($summaryRow['received'] ?? 0),
        'repaired' => (int)($summaryRow['repaired'] ?? 0),
        'issued' => (int)($summaryRow['issued'] ?? 0),
        'cancelled' => (int)($summaryRow['cancelled'] ?? 0),
        'warranty' => (int)($summaryRow['warranty'] ?? 0),
        'success_rate' => crmStatisticsRate($summaryRow['repaired'] ?? 0, $summaryRow['received'] ?? 0),
        'average_repair_seconds' => (int)($summaryRow['repair_time_count'] ?? 0) > 0
            ? (float)$summaryRow['repair_seconds'] / (int)$summaryRow['repair_time_count']
            : null,
        'current_in_progress' => $currentInProgress,
        'current_diagnostics' => (int)($currentByStatus['Diagnostics'] ?? 0),
        'current_approval' => (int)($currentByStatus['Approval'] ?? 0) + (int)($currentByStatus['Pending Approval'] ?? 0),
        'current_waiting_parts' => $currentWaitingParts,
        'current_ready' => $currentReady,
        'current_cancelled' => $currentCancelled,
        'overdue' => $overdueTotal,
    ];

    return [
        'scope' => $scope,
        'summary' => $summary,
        'financial' => $financial,
        'by_device' => $byDevice,
        'by_device_finance' => $financeBatch['by_device_type'] ?? [],
        'technicians' => $technicians,
        'status_board' => $statusBoard,
        'timeline' => $timeline,
        'timeline_monthly' => $rangeDays > 45,
        'overdue_orders' => $overdueOrders,
        'reasons' => $reasons,
        'sla' => [
            'new_hours' => max(0, $slaNewHours),
            'progress_hours' => max(0, $slaProgressHours),
        ],
    ];
}
