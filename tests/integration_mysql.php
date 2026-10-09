<?php
/**
 * MySQL/MariaDB integration tests for the binding finance, stock, numbering, VAT and
 * scoping rules. They need a real database that has been migrated with run_migrations.php
 * and use the least-privilege web account (DB_USER), so they also prove the runtime needs
 * no DDL.
 *
 * Run (CI does this):  CRM_INTEGRATION_DB=1 php tests/integration_mysql.php
 * Refuses to run unless DB_NAME ends in _test, _ci or _audit: it writes and deletes rows.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (getenv('CRM_INTEGRATION_DB') !== '1') {
    echo "SKIP: set CRM_INTEGRATION_DB=1 and DB_* for a disposable database to run integration tests\n";
    exit(0);
}
if (!preg_match('/_(test|ci|audit)$/', (string)getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end with _test, _ci or _audit.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/reports_stats.php';
require_once __DIR__ . '/../models/OrderStatusService.php';
require_once __DIR__ . '/../models/InvoiceAutomation.php';
require_once __DIR__ . '/../models/InvoicePolicy.php';

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}
function near(float $expected, float $actual): bool
{
    return abs($expected - $actual) < 0.005;
}

// ── Fixture ─────────────────────────────────────────────────────────────────
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['invoice_items', 'invoices', 'order_items', 'order_status_log', 'order_attachments', 'orders', 'inventory', 'tech_permissions', 'technicians', 'customers'] as $table) {
    $pdo->exec("DELETE FROM `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
set_setting('acc_is_vat_payer', '0');
set_setting('acc_auto_create_invoice', '0');
set_setting('acc_invoice_prefix', '2026');
set_setting('acc_invoice_next_number', '1');
set_setting('myinvoice_enabled', '0');

$pdo->exec("INSERT INTO technicians (id, name, is_active, engineer_rate) VALUES (1, 'Tech A', 1, 50), (2, 'Tech B', 1, 40)");
$pdo->exec("INSERT INTO customers (id, first_name, last_name) VALUES (1, 'Jan', 'Novak'), (2, 'Eva', 'Mala')");
$pdo->exec("INSERT INTO inventory (id, part_name, quantity, cost_price, sale_price) VALUES (1, 'Display', 10, 300.00, 900.00), (2, 'Battery', 1, NULL, 500.00)");

$insertOrder = $pdo->prepare(
    "INSERT INTO orders (id, customer_id, technician_id, device_type, device_model, status, final_cost, estimated_cost, extra_expenses, shipping_date)
     VALUES (?, ?, ?, 'Phone', 'X', ?, ?, ?, ?, ?)"
);

// ── 1. Finance formulas (binding, root AGENTS.md) ───────────────────────────
// Order 101: invoice 1000 (latest non-credit, non-cancelled), part cost snapshot 300 x2, extra 100.
$insertOrder->execute([101, 1, 1, 'Issued', 1200, 1100, 100, '2026-09-10 10:00:00']);
$pdo->exec("INSERT INTO order_items (order_id, inventory_id, quantity, price, cost_price) VALUES (101, 1, 2, 900, 300)");
$pdo->exec("INSERT INTO invoices (invoice_number, customer_id, order_id, date_issue, date_tax, date_due, total_amount, status, created_at)
            VALUES ('OLD-1', 1, 101, '2026-09-01', '2026-09-01', '2026-09-15', 800, 'cancelled', '2026-09-01 10:00:00'),
                   ('INV-1', 1, 101, '2026-09-10', '2026-09-10', '2026-09-24', 1000, 'issued', '2026-09-10 10:00:00')");
// Changing the live inventory cost must not change the snapshot-based payroll.
$pdo->exec('UPDATE inventory SET cost_price = 9999 WHERE id = 1');
// Order 102: no invoice -> final_cost 500; unknown part cost -> falls back to item price 700 -> base < 0 -> payout floored at 0.
$insertOrder->execute([102, 2, 1, 'Issued', 500, null, 0, '2026-09-12 10:00:00']);
$pdo->exec("INSERT INTO order_items (order_id, inventory_id, quantity, price, cost_price) VALUES (102, NULL, 1, 700, NULL)");
// Order 103: other technician, no final cost -> estimated 400, outside the period.
$insertOrder->execute([103, 1, 2, 'Issued', null, 400, 0, '2026-08-01 10:00:00']);

$batch = getDetailedStatsBatch($pdo, '2026-09-01', '2026-09-30');
$techA = $batch['by_technician'][1];
check(near(1500.0, (float)$techA['revenue']), 'revenue = latest non-cancelled invoice (1000) + final cost without invoice (500)');
check(near(1300.0, (float)$techA['parts_cost']), 'parts cost = snapshot 2x300 + fallback item price 700');
check(near(100.0, (float)$techA['expenses']), 'extra expenses summed');
check(near(150.0, (float)$techA['earnings']), 'payout = max(0, 1000-600-100) x 50% + max(0, 500-700) x 50% = 150 (floored per order)');
check(near(1500.0 - 1300.0 - 100.0, (float)$techA['net_profit']), 'net profit = revenue - parts - expenses (payouts not subtracted)');
check(near((float)$techA['net_profit'] - (float)$techA['earnings'], (float)$techA['sc_income']), 'SC income = net profit - payouts');
check(!isset($batch['by_technician'][2]) || near(0.0, (float)$batch['by_technician'][2]['revenue']), 'orders outside the period are excluded');

$payroll = crmGetTechnicianPayroll($pdo, 1, '2026-09-01', '2026-09-30');
check(near((float)$techA['earnings'], (float)$payroll['totals']['earnings']), 'payroll lines and summary use the same formulas');
check(($payroll['orders'][0]['customer'] ?? '') === 'Jan Novak', 'payroll lines carry the customer name');

// ── 2. Stock, shipping_date and history through OrderStatusService ──────────
$_SESSION = ['user_id' => 't1', 'role' => 'technician', 'tech_id' => 1, '_perms' => [], '_perms_at' => time()];
$insertOrder->execute([201, 1, 1, 'In Repair', 900, 900, 0, null]);
$pdo->exec("INSERT INTO order_items (order_id, inventory_id, quantity, price, cost_price) VALUES (201, 1, 3, 900, 300)");
$pdo->exec('UPDATE inventory SET quantity = 10 WHERE id = 1');
$stock = static fn() => (int)$pdo->query('SELECT quantity FROM inventory WHERE id = 1')->fetchColumn();
$transition = static function (string $from, string $to) use ($pdo): void {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE orders SET status = ? WHERE id = 201')->execute([$to]);
    OrderStatusService::applyInTransaction($pdo, 201, $from, $to, 900);
    $pdo->commit();
};
$transition('In Repair', 'Ready');
check($stock() === 7, 'entering Ready consumes parts');
$transition('Ready', 'Issued');
check($stock() === 7, 'Ready -> Issued does not consume again');
check($pdo->query('SELECT shipping_date FROM orders WHERE id = 201')->fetchColumn() !== null, 'entering Issued stamps shipping_date');
$transition('Issued', 'In Repair');
check($stock() === 10, 'leaving the consuming states returns parts');
check($pdo->query('SELECT shipping_date FROM orders WHERE id = 201')->fetchColumn() === null, 'reopening an issued order clears shipping_date (re-issue lands in the new period)');
$log = $pdo->query("SELECT changed_by, changed_role FROM order_status_log WHERE order_id = 201 ORDER BY id LIMIT 1")->fetch();
check($log && (int)$log['changed_by'] === 1 && $log['changed_role'] === 'technician', 'status history stores the numeric technician id (strict SQL mode)');

$pdo->exec('UPDATE inventory SET quantity = 1 WHERE id = 1');
$blocked = false;
try {
    $transition('In Repair', 'Ready');
} catch (Throwable $e) {
    $blocked = true;
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
check($blocked && $stock() === 1, 'stock can never go negative; the whole transition rolls back');

// ── 3. Invoice numbering and VAT policy ─────────────────────────────────────
$pdo->beginTransaction();
$first = crmReserveInvoiceNumber($pdo);
$second = crmReserveInvoiceNumber($pdo);
$pdo->commit();
check($first === '20260001' && $second === '20260002', 'reserved invoice numbers are sequential');
$pdo->exec("INSERT INTO invoices (invoice_number, customer_id, date_issue, date_tax, date_due, total_amount) VALUES ('20260003', 1, '2026-10-01', '2026-10-01', '2026-10-15', 1)");
$pdo->beginTransaction();
check(crmReserveInvoiceNumber($pdo) === '20260004', 'a number already used by a manual invoice is skipped');
crmAdvanceInvoiceCounterPast($pdo, '20260050');
check(crmReserveInvoiceNumber($pdo) === '20260051', 'a manual number in the series moves the counter past it');
$pdo->commit();

$nonPayer = crmInvoiceAmountsFromCustomerTotal(1210.0, ['payer' => false, 'rate' => 0.0]);
check(near(1210.0, $nonPayer['total']) && near(0.0, $nonPayer['vat']) && near(1210.0, $nonPayer['net']), 'non-VAT-payer: total = customer charge, VAT 0');
$payer = crmInvoiceAmountsFromCustomerTotal(1210.0, ['payer' => true, 'rate' => 21.0]);
check(near(1210.0, $payer['total']) && near(210.0, $payer['vat']) && near(1000.0, $payer['net']), 'VAT payer: VAT extracted from the customer charge');

$insertOrder->execute([301, 2, 1, 'Ready', 1200, 1000, 0, null]);
$pdo->beginTransaction();
$auto = createLocalInvoiceForCompletedOrder($pdo, 301, 1200);
$pdo->commit();
$autoRow = $pdo->query('SELECT total_amount, vat_amount, is_vat_payer FROM invoices WHERE id = ' . (int)($auto['id'] ?? 0))->fetch();
check($autoRow && near(1200.0, (float)$autoRow['total_amount']) && near(0.0, (float)$autoRow['vat_amount']), 'auto invoice total equals the final cost (no VAT added on top)');

$pdo->exec("UPDATE invoices SET myinvoice_invoice_id = 777 WHERE id = " . (int)($auto['id'] ?? 0));
$pdo->beginTransaction();
cancelAutoInvoicesForOrder($pdo, 301);
$pdo->commit();
check($pdo->query('SELECT status FROM invoices WHERE id = ' . (int)($auto['id'] ?? 0))->fetchColumn() === 'issued', 'an auto invoice already synced to MyInvoice is not cancelled locally');

// ── 4. Technician scoping ───────────────────────────────────────────────────
$_SESSION = ['user_id' => 't2', 'role' => 'technician', 'tech_id' => 2, '_perms' => [], '_perms_at' => time()];
check(!currentUserCanViewOrder(101) && currentUserCanViewOrder(103), 'technicians see only their own orders');
check(!currentUserCanViewCustomer(2) && currentUserCanViewCustomer(1), 'technicians see only customers they share an order with');
$search = searchOrdersList($pdo, '', 2, null, 10, 0, true);
$ids = array_map(static fn($o) => (int)$o['id'], $search['orders']);
check($ids === [103], 'order search is scoped to the technician');
$_SESSION = ['user_id' => 1, 'role' => 'admin', 'tech_id' => null];
check(currentUserCanViewOrder(101) && currentUserCanViewOrder(103), 'administrators see every order');
$counts = countOrdersByStatusGroups(getDashboardStatusGroups(), null);
check($counts['ready'] >= 4 && $counts['progress'] >= 1, 'dashboard tiles are counted with one grouped query');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} integration check(s) failed\n");
    exit(1);
}
echo "OK: MySQL integration checks passed\n";
