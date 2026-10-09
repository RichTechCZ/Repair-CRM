<?php
/**
 * Financial / operational reporting helpers.
 *
 * Binding formulas (root AGENTS.md User Preferences):
 * - customer revenue = latest non-credit invoice total; fallback final_cost, then estimated_cost
 * - parts purchase cost = Σ qty × order_items.cost_price snapshot (then inventory.cost_price, then order_items.price)
 * - never add order_items.price to an invoice total
 * - net profit = customer revenue − parts cost − extra expenses
 * - engineer payout = max(0, customer revenue − parts cost − extra expenses) × rate%
 * - SC income = net profit − engineer payouts
 */

/**
 * SQL for the purchase cost of order lines. Uses the order_items.cost_price snapshot (migration 008)
 * once the column exists, and falls back to the live inventory cost on a not-yet-migrated schema,
 * so reports keep working during a rollout.
 */
function crmOrderItemCostSql(): string
{
    $snapshot = function_exists('tableColumnExists') && tableColumnExists('order_items', 'cost_price')
        ? 'oi.cost_price, '
        : '';
    return 'SUM(oi.quantity * COALESCE(' . $snapshot . 'i.cost_price, oi.price, 0))';
}

function crmEmptyDetailedStats(float $engineerRate = 50.0): array
{
    return [
        'received' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'revenue' => 0.0,
        'finance_orders' => 0,
        'expenses' => 0.0,
        'parts_cost' => 0.0,
        'net_profit' => 0.0,
        'engineer_rate' => $engineerRate,
        'earnings' => 0.0,
        'sc_income' => 0.0,
    ];
}

function crmFinalizeDetailedStats(array $stats): array
{
    $stats['net_profit'] = $stats['revenue'] - $stats['parts_cost'] - $stats['expenses'];
    $stats['sc_income'] = $stats['net_profit'] - $stats['earnings'];
    return $stats;
}

/**
 * Loads every technician and the overall total in a constant number of
 * queries. Status metrics use immutable status-transition timestamps rather
 * than orders.updated_at, so later edits cannot move historical work.
 *
 * @return array{
 *   all:array,
 *   by_technician:array<int,array>,
 *   by_device_type:array<string,array{revenue:float,finance_orders:int}>,
 *   rates:array<int,float>
 * }
 *
 * @param int|null $scopeTechnicianId Optional server-resolved technician scope.
 */
function getDetailedStatsBatch(PDO $pdo, string $start, string $end, ?int $scopeTechnicianId = null): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . '|' . $start . '|' . $end . '|' . (string)($scopeTechnicianId ?? 'all');
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $startAt = $start . ' 00:00:00';
    $endAt = $end . ' 23:59:59';

    $rates = [];
    $byTechnician = [];
    if ($scopeTechnicianId === null) {
        $rateRows = $pdo->query('SELECT id, engineer_rate FROM technicians')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rateStmt = $pdo->prepare('SELECT id, engineer_rate FROM technicians WHERE id = ? LIMIT 1');
        $rateStmt->execute([$scopeTechnicianId]);
        $rateRows = $rateStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rateRows as $row) {
        $technicianId = (int)$row['id'];
        $rate = (float)($row['engineer_rate'] ?? 50);
        $rates[$technicianId] = $rate;
        $byTechnician[$technicianId] = crmEmptyDetailedStats($rate);
    }
    $all = crmEmptyDetailedStats();
    $byDeviceType = [];

    $statusScopeSql = '';
    $statusScopeParams = [];
    if ($scopeTechnicianId !== null) {
        $statusScopeSql = ' WHERE o.technician_id = ?';
        $statusScopeParams[] = $scopeTechnicianId;
    }

    $statusSql = "
        SELECT
            o.technician_id,
            SUM(CASE WHEN o.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS received,
            SUM(CASE
                WHEN o.status IN ('Diagnostics','In Repair','In Progress','Waiting for Parts')
                 AND status_dates.in_progress_at BETWEEN ? AND ?
                THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE
                WHEN o.status IN ('Ready','Issued','Completed','Collected')
                 AND status_dates.completed_at BETWEEN ? AND ?
                THEN 1 ELSE 0 END) AS completed,
            SUM(CASE
                WHEN o.status IN ('Issued Without Repair','Repair Cancelled','Cancelled')
                 AND status_dates.cancelled_at BETWEEN ? AND ?
                THEN 1 ELSE 0 END) AS cancelled
        FROM orders o
        LEFT JOIN (
            SELECT
                order_id,
                MAX(CASE
                    WHEN new_status IN ('Diagnostics','In Repair','In Progress','Waiting for Parts')
                    THEN changed_at END) AS in_progress_at,
                MAX(CASE
                    WHEN new_status IN ('Ready','Issued','Completed','Collected')
                    THEN changed_at END) AS completed_at,
                MAX(CASE
                    WHEN new_status IN ('Issued Without Repair','Repair Cancelled','Cancelled')
                    THEN changed_at END) AS cancelled_at
            FROM order_status_log
            GROUP BY order_id
        ) status_dates ON status_dates.order_id = o.id
        " . $statusScopeSql . "
        GROUP BY o.technician_id
    ";
    $statusStmt = $pdo->prepare($statusSql);
    $statusStmt->execute(array_merge([
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
        $startAt, $endAt,
    ], $statusScopeParams));

    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $technicianId = (int)($row['technician_id'] ?? 0);
        if (!isset($byTechnician[$technicianId])) {
            $byTechnician[$technicianId] = crmEmptyDetailedStats($rates[$technicianId] ?? 50.0);
        }
        foreach (['received', 'in_progress', 'completed', 'cancelled'] as $metric) {
            $value = (int)($row[$metric] ?? 0);
            $byTechnician[$technicianId][$metric] += $value;
            $all[$metric] += $value;
        }
    }

    $financeSql = "
        SELECT
            o.id,
            o.technician_id,
            o.device_type,
            COALESCE(latest_invoice.total_amount, o.final_cost, o.estimated_cost, 0) AS customer_total,
            COALESCE(o.extra_expenses, 0) AS extra_expenses,
            COALESCE(parts.inventory_cost, 0) AS inventory_cost
        FROM orders o
        LEFT JOIN (
            SELECT
                order_id,
                CAST(
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CAST(total_amount AS CHAR)
                            ORDER BY COALESCE(payment_date, date_issue, created_at) DESC, id DESC
                            SEPARATOR ','
                        ),
                        ',',
                        1
                    ) AS DECIMAL(15,2)
                ) AS total_amount,
                MAX(CASE WHEN status = 'paid' THEN payment_date END) AS latest_payment_date
            FROM invoices
            WHERE (invoice_type IS NULL OR invoice_type != 'credit_note')
              AND status <> 'cancelled'
            GROUP BY order_id
        ) latest_invoice ON latest_invoice.order_id = o.id
        LEFT JOIN (
            SELECT
                oi.order_id,
                " . crmOrderItemCostSql() . " AS inventory_cost
            FROM order_items oi
            LEFT JOIN inventory i ON i.id = oi.inventory_id
            GROUP BY oi.order_id
        ) parts ON parts.order_id = o.id
        WHERE o.status IN ('Issued','Collected')
          AND COALESCE(latest_invoice.latest_payment_date, DATE(o.shipping_date)) BETWEEN ? AND ?
          " . ($scopeTechnicianId !== null ? 'AND o.technician_id = ?' : '') . "
    ";
    $financeStmt = $pdo->prepare($financeSql);
    $financeParams = [$start, $end];
    if ($scopeTechnicianId !== null) {
        $financeParams[] = $scopeTechnicianId;
    }
    $financeStmt->execute($financeParams);

    foreach ($financeStmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $technicianId = (int)($order['technician_id'] ?? 0);
        $rate = $rates[$technicianId] ?? 50.0;
        if (!isset($byTechnician[$technicianId])) {
            $byTechnician[$technicianId] = crmEmptyDetailedStats($rate);
        }

        $customerTotal = (float)($order['customer_total'] ?? 0);
        $extraExpenses = (float)($order['extra_expenses'] ?? 0);
        $partsCost = (float)($order['inventory_cost'] ?? 0);
        $earnings = max(0.0, $customerTotal - $partsCost - $extraExpenses) * ($rate / 100);

        $deviceType = trim((string)($order['device_type'] ?? ''));
        if ($deviceType === '') {
            $deviceType = 'Other';
        }
        if (!isset($byDeviceType[$deviceType])) {
            $byDeviceType[$deviceType] = ['revenue' => 0.0, 'finance_orders' => 0];
        }
        $byDeviceType[$deviceType]['revenue'] += $customerTotal;
        $byDeviceType[$deviceType]['finance_orders']++;

        foreach ([$technicianId, null] as $targetId) {
            if ($targetId === null) {
                $all['revenue'] += $customerTotal;
                $all['finance_orders']++;
                $all['expenses'] += $extraExpenses;
                $all['parts_cost'] += $partsCost;
                $all['earnings'] += $earnings;
            } else {
                $byTechnician[$targetId]['revenue'] += $customerTotal;
                $byTechnician[$targetId]['finance_orders']++;
                $byTechnician[$targetId]['expenses'] += $extraExpenses;
                $byTechnician[$targetId]['parts_cost'] += $partsCost;
                $byTechnician[$targetId]['earnings'] += $earnings;
            }
        }
    }

    foreach ($byTechnician as $technicianId => $stats) {
        $byTechnician[$technicianId] = crmFinalizeDetailedStats($stats);
    }
    $all = crmFinalizeDetailedStats($all);

    return $cache[$cacheKey] = [
        'all' => $all,
        'by_technician' => $byTechnician,
        'by_device_type' => $byDeviceType,
        'rates' => $rates,
    ];
}

/**
 * Backwards-compatible reporting entry point.
 */
function getDetailedStats(PDO $pdo, string $start, string $end, $tech_id = null): array
{
    $batch = getDetailedStatsBatch($pdo, $start, $end);
    if ($tech_id === null || $tech_id === '') {
        return $batch['all'];
    }

    $technicianId = (int)$tech_id;
    return $batch['by_technician'][$technicianId]
        ?? crmEmptyDetailedStats($batch['rates'][$technicianId] ?? 50.0);
}

/**
 * Per-order payroll lines for one technician in a finance period.
 * Uses the same revenue/parts/expenses/earnings formulas as getDetailedStatsBatch().
 *
 * @return array{
 *   technician: ?array{id:int,name:string},
 *   rate: float,
 *   orders: list<array{
 *     id:int,finance_date:?string,device:string,customer_total:float,
 *     parts_cost:float,extra_expenses:float,net_profit:float,earnings:float
 *   }>,
 *   totals: array{
 *     count:int,revenue:float,parts_cost:float,expenses:float,
 *     net_profit:float,earnings:float,sc_income:float
 *   }
 * }
 */
function crmGetTechnicianPayroll(PDO $pdo, int $technicianId, string $start, string $end): array
{
    $rate = 50.0;
    $techName = null;
    $techStmt = $pdo->prepare('SELECT id, name, engineer_rate FROM technicians WHERE id = ? LIMIT 1');
    $techStmt->execute([$technicianId]);
    $techRow = $techStmt->fetch(PDO::FETCH_ASSOC);
    if ($techRow) {
        $techName = (string)($techRow['name'] ?? '');
        $rate = (float)($techRow['engineer_rate'] ?? 50.0);
    }

    $sql = "
        SELECT
            o.id,
            o.device_brand,
            o.device_model,
            c.first_name AS customer_first_name,
            c.last_name AS customer_last_name,
            c.company AS customer_company,
            COALESCE(o.extra_expenses, 0) AS extra_expenses,
            COALESCE(latest_invoice.total_amount, o.final_cost, o.estimated_cost, 0) AS customer_total,
            COALESCE(parts.inventory_cost, 0) AS inventory_cost,
            COALESCE(latest_invoice.latest_payment_date, DATE(o.shipping_date)) AS finance_date
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN (
            SELECT
                order_id,
                CAST(
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            CAST(total_amount AS CHAR)
                            ORDER BY COALESCE(payment_date, date_issue, created_at) DESC, id DESC
                            SEPARATOR ','
                        ),
                        ',',
                        1
                    ) AS DECIMAL(15,2)
                ) AS total_amount,
                MAX(CASE WHEN status = 'paid' THEN payment_date END) AS latest_payment_date
            FROM invoices
            WHERE (invoice_type IS NULL OR invoice_type != 'credit_note')
              AND status <> 'cancelled'
            GROUP BY order_id
        ) latest_invoice ON latest_invoice.order_id = o.id
        LEFT JOIN (
            SELECT
                oi.order_id,
                " . crmOrderItemCostSql() . " AS inventory_cost
            FROM order_items oi
            LEFT JOIN inventory i ON i.id = oi.inventory_id
            GROUP BY oi.order_id
        ) parts ON parts.order_id = o.id
        WHERE o.technician_id = ?
          AND o.status IN ('Issued','Collected')
          AND COALESCE(latest_invoice.latest_payment_date, DATE(o.shipping_date)) BETWEEN ? AND ?
        ORDER BY finance_date ASC, o.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$technicianId, $start, $end]);

    $orders = [];
    $totals = [
        'count' => 0,
        'revenue' => 0.0,
        'parts_cost' => 0.0,
        'expenses' => 0.0,
        'net_profit' => 0.0,
        'earnings' => 0.0,
        'sc_income' => 0.0,
    ];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerTotal = (float)($row['customer_total'] ?? 0);
        $partsCost = (float)($row['inventory_cost'] ?? 0);
        $extra = (float)($row['extra_expenses'] ?? 0);
        $net = $customerTotal - $partsCost - $extra;
        $earn = max(0.0, $customerTotal - $partsCost - $extra) * ($rate / 100);
        $device = trim((string)($row['device_brand'] ?? '') . ' ' . (string)($row['device_model'] ?? ''));

        $orders[] = [
            'id' => (int)$row['id'],
            'finance_date' => $row['finance_date'] ?? null,
            'device' => $device,
            'customer' => trim((string)($row['customer_company'] ?? '')) !== ''
                ? (string)$row['customer_company']
                : trim(($row['customer_first_name'] ?? '') . ' ' . ($row['customer_last_name'] ?? '')),
            'customer_total' => $customerTotal,
            'parts_cost' => $partsCost,
            'extra_expenses' => $extra,
            'net_profit' => $net,
            'earnings' => $earn,
        ];

        $totals['count']++;
        $totals['revenue'] += $customerTotal;
        $totals['parts_cost'] += $partsCost;
        $totals['expenses'] += $extra;
        $totals['net_profit'] += $net;
        $totals['earnings'] += $earn;
    }
    $totals['sc_income'] = $totals['net_profit'] - $totals['earnings'];

    return [
        'technician' => $techRow
            ? ['id' => (int)$techRow['id'], 'name' => $techName ?? '']
            : null,
        'rate' => $rate,
        'orders' => $orders,
        'totals' => $totals,
    ];
}
