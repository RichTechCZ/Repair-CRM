<?php
require_once __DIR__ . '/statistics_dashboard.php';

/** @return array{is_admin:bool,technician_id:?int} */
function crmWorkQueueScope(): array
{
    if (!empty($_SESSION['user_id'])) {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'admin') {
            return ['is_admin' => true, 'technician_id' => null];
        }

        if ($role === 'technician' && ($technicianId = currentTechnicianId()) > 0) {
            return ['is_admin' => false, 'technician_id' => $technicianId];
        }
    }

    throw new RuntimeException('Dashboard access denied.');
}

/** Financial metrics use the same owner as the order queue. */
function crmWorkQueueFinance(PDO $pdo, string $start, string $end): array
{
    $scope = crmWorkQueueScope();
    $stats = getDetailedStatsBatch($pdo, $start, $end, $scope['technician_id'])['all'];
    $finance = [
        'revenue' => $stats['revenue'],
        'parts_cost' => $stats['parts_cost'],
        'expenses' => $stats['expenses'],
        'earnings' => $stats['earnings'],
    ];
    if ($scope['is_admin']) {
        $finance['sc_income'] = $stats['sc_income'];
    }
    return $finance;
}

/** Current workshop queues. Counters, staff workload and pages use the same scoped order set. */
function crmWorkQueue(PDO $pdo, string $queue, int $requestedPage, bool $loadRows = true): array
{
    $scope = crmWorkQueueScope();
    $params = [$pdo->query('SELECT NOW()')->fetchColumn()];
    $scopeSql = crmStatisticsScopeCondition($scope['technician_id'], $params);
    $limitSql = crmStageTimeLimitSql((int)get_setting('sla_new_hours', 24), (int)get_setting('sla_progress_hours', 72));
    $stageStart = crmCurrentStageStartSql();
    $stages = "SELECT o.id, o.technician_id, o.status, o.priority,
                      TIMESTAMPDIFF(SECOND, {$stageStart}, ?) AS age_seconds,
                      {$limitSql} AS time_limit
               FROM orders o
               WHERE o.status IN ('Accepted','New','Diagnostics','Approval','Pending Approval','In Repair',
                                  'In Progress','Waiting for Parts','Ready','Completed')"
        . ($scopeSql === '' ? '' : " AND {$scopeSql}");

    $overdue = 'q.age_seconds > q.time_limit';
    $conditions = [
        'attention' => "({$overdue} OR q.priority = 'High')",
        'overdue' => $overdue,
        'approval' => "q.status IN ('Approval','Pending Approval')",
        'parts' => "q.status = 'Waiting for Parts'",
        'ready' => "q.status IN ('Ready','Completed')",
    ];
    $queue = isset($conditions[$queue]) ? $queue : 'attention';
    $counts = array_fill_keys(array_keys($conditions), 0);
    $metrics = [];
    foreach ($conditions as $key => $condition) {
        $metrics[] = "COUNT(CASE WHEN {$condition} THEN 1 END) AS {$key}";
    }
    $stmt = $pdo->prepare("SELECT q.technician_id, " . implode(', ', $metrics) . ",
            COUNT(CASE WHEN q.status IN ('Diagnostics','In Repair','In Progress') THEN 1 END) AS in_work
            FROM ({$stages}) q GROUP BY q.technician_id");
    $stmt->execute($params);
    $byTechnician = [];
    foreach ($stmt as $row) {
        foreach ($counts as $key => $count) {
            $counts[$key] += (int)$row[$key];
        }
        $byTechnician[(int)$row['technician_id']] = $row;
    }

    $pageSize = 15;
    $pages = max(1, (int)ceil($counts[$queue] / $pageSize));
    $page = max(1, min($pages, $requestedPage));
    $rows = [];
    if ($loadRows && $counts[$queue] > 0) {
        $offset = ($page - 1) * $pageSize;
        $sort = $queue === 'attention' || $queue === 'overdue'
            ? 'CASE WHEN ' . $overdue . ' THEN q.age_seconds - q.time_limit ELSE 0 END DESC, '
            : '';
        $stmt = $pdo->prepare("SELECT q.id, q.status, q.priority, q.age_seconds,
                q.age_seconds - q.time_limit AS overdue_seconds, o.device_type, o.device_brand, o.device_model,
                o.estimated_cost, o.final_cost, o.shipping_method, c.first_name, c.last_name, c.customer_type,
                c.company, c.phone, t.name AS tech_name
            FROM ({$stages}) q
            JOIN orders o ON o.id = q.id
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN technicians t ON t.id = q.technician_id
            WHERE {$conditions[$queue]}
            ORDER BY {$sort}q.age_seconds DESC, q.id ASC LIMIT {$pageSize} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return compact('counts', 'byTechnician', 'queue', 'page', 'pages', 'rows', 'scope');
}
