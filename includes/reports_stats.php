<?php
/**
 * Financial / operational reporting helpers.
 *
 * Binding formulas (root AGENTS.md User Preferences):
 * - final_cost = repair WORK cost only; parts billed via order_items.price
 * - parts purchase cost = Σ qty × inventory.cost_price (fallback order_items.price)
 * - net profit = revenue (work + parts) − parts cost − extra expenses
 * - engineer payout = max(0, work − parts cost − ½ extra) × rate%
 * - SC income = net profit − engineer payouts
 */

/**
 * Aggregate order/financial stats for a date range and optional technician.
 *
 * @param PDO $pdo
 * @param string $start Y-m-d
 * @param string $end Y-m-d
 * @param int|string|null $tech_id
 * @return array{
 *   received:int|string,
 *   in_progress:int|string,
 *   completed:int|string,
 *   cancelled:int|string,
 *   revenue:float,
 *   expenses:float,
 *   parts_cost:float,
 *   net_profit:float,
 *   engineer_rate:float,
 *   earnings:float,
 *   sc_income:float
 * }
 */
function getDetailedStats($pdo, $start, $end, $tech_id = null) {
    $params = [$start . ' 00:00:00', $end . ' 23:59:59'];
    $tech_cond = $tech_id ? " AND technician_id = ?" : "";
    if ($tech_id) {
        $params[] = $tech_id;
    }

    // Received
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE (created_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $received = $stmt->fetchColumn();

    // In Progress
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Diagnostics','In Repair','In Progress','Waiting for Parts') AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $in_progress = $stmt->fetchColumn();

    // Ready/Issued (Done)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Ready','Issued','Completed','Collected') AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $completed = $stmt->fetchColumn();

    // Cancelled / issued without repair
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('Issued Without Repair','Repair Cancelled','Cancelled') AND (updated_at BETWEEN ? AND ?)" . $tech_cond);
    $stmt->execute($params);
    $cancelled = $stmt->fetchColumn();

    // ─── Financials ───────────────────────────────────────────────────────────
    // Finance date priority:
    //   1. payment_date from linked invoice (status='paid')
    //   2. shipping_date of the order (fallback)
    // Only issued orders are counted.
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
            COALESCE(
                (SELECT inv.payment_date FROM invoices inv
                 WHERE inv.order_id = o.id AND inv.status = 'paid'
                   AND (inv.invoice_type IS NULL OR inv.invoice_type != 'credit_note')
                 ORDER BY inv.payment_date DESC LIMIT 1),
                DATE(o.shipping_date)
            ) AS finance_date,
            (SELECT COALESCE(SUM(oi2.quantity * COALESCE(invt.cost_price, oi2.price)), 0)
             FROM order_items oi2
             LEFT JOIN inventory invt ON oi2.inventory_id = invt.id
             WHERE oi2.order_id = o.id) AS inventory_cost,
            (SELECT COALESCE(SUM(oi3.quantity * oi3.price), 0)
             FROM order_items oi3
             WHERE oi3.order_id = o.id) AS parts_revenue
        FROM orders o
        WHERE o.status IN ('Issued','Collected')
          AND COALESCE(
                (SELECT inv.payment_date FROM invoices inv
                 WHERE inv.order_id = o.id AND inv.status = 'paid'
                   AND (inv.invoice_type IS NULL OR inv.invoice_type != 'credit_note')
                 ORDER BY inv.payment_date DESC LIMIT 1),
                DATE(o.shipping_date)
              ) BETWEEN ? AND ?
    " . $fin_tech_cond;

    $stmt = $pdo->prepare($sql_orders);
    $stmt->execute($fin_params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $revenue = 0.0;
    $expenses = 0.0;
    $parts_cost = 0.0;
    $engineer_earnings = 0.0;

    $stmt_rates = $pdo->query("SELECT id, engineer_rate FROM technicians");
    $rates = [];
    while ($row = $stmt_rates->fetch(PDO::FETCH_ASSOC)) {
        $rates[$row['id']] = (float)($row['engineer_rate'] ?? 50);
    }
    $engineer_rate = $tech_id ? ($rates[$tech_id] ?? 50) : 50;

    foreach ($orders as $o) {
        $work_cost = $o['final_cost'] !== null ? (float)$o['final_cost']
                : (float)($o['estimated_cost'] ?? 0);
        $parts_rev = (float)($o['parts_revenue'] ?? 0);
        $rev = $work_cost + $parts_rev;
        $exp = (float)($o['extra_expenses'] ?? 0);
        $p_cost = (float)($o['inventory_cost'] ?? 0);

        $revenue += $rev;
        $expenses += $exp;
        $parts_cost += $p_cost;

        $earn_base = $work_cost - $p_cost - ($exp / 2);
        if ($earn_base < 0) {
            $earn_base = 0;
        }

        $rate = $rates[$o['technician_id']] ?? $engineer_rate;
        $engineer_earnings += $earn_base * ($rate / 100);
    }

    $net_profit = $revenue - $parts_cost - $expenses;
    $sc_income = $net_profit - $engineer_earnings;

    return [
        'received' => $received,
        'in_progress' => $in_progress,
        'completed' => $completed,
        'cancelled' => $cancelled,
        'revenue' => $revenue,
        'expenses' => $expenses,
        'parts_cost' => $parts_cost,
        'net_profit' => $net_profit,
        'engineer_rate' => $engineer_rate,
        'earnings' => $engineer_earnings,
        'sc_income' => $sc_income,
    ];
}
