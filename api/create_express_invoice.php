<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/InvoicePolicy.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'create_express_invoice',
]);

$order_id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invoice_id = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
$item_name = trim((string)($_POST['item_name'] ?? ''));
$date_issue = (string)($_POST['date_issue'] ?? date('Y-m-d'));
$date_due = (string)($_POST['date_due'] ?? date('Y-m-d', strtotime('+14 days')));
$total_amount_raw = $_POST['total_amount'] ?? null;

if (
    !$order_id ||
    !is_numeric($total_amount_raw) ||
    !is_finite((float)$total_amount_raw) ||
    (float)$total_amount_raw < 0 ||
    $item_name === '' ||
    mb_strlen($item_name) > 255 ||
    mb_strlen($invoice_number) > 64
) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}
$total_amount = (float)$total_amount_raw;

$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$isValidDate($date_issue) || !$isValidDate($date_due) || $date_due < $date_issue) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, customer_id FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException(__('not_found'));
    }

    $numberTaken = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? AND id <> ? LIMIT 1');

    if ($invoice_id) {
        $status = (string)($_POST['status'] ?? 'issued');
        $payment_method = (string)($_POST['payment_method'] ?? 'bank_transfer');
        if (!in_array($status, ['draft', 'issued', 'paid', 'overdue', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid invoice status.');
        }
        if (!in_array($payment_method, ['bank_transfer', 'cash', 'card', 'cod'], true)) {
            throw new InvalidArgumentException('Invalid payment method.');
        }

        $ownership_check = $pdo->prepare(
            "SELECT id, payment_date, is_vat_payer, invoice_number FROM invoices
             WHERE id = ? AND order_id = ? AND (invoice_type IS NULL OR invoice_type <> 'credit_note') FOR UPDATE"
        );
        $ownership_check->execute([$invoice_id, $order_id]);
        $existing_invoice = $ownership_check->fetch(PDO::FETCH_ASSOC);
        if (!$existing_invoice) {
            throw new RuntimeException('Invoice does not belong to this order.');
        }
        if ($invoice_number === '') {
            $invoice_number = (string)$existing_invoice['invoice_number'];
        }
        $numberTaken->execute([$invoice_number, $invoice_id]);
        if ($numberTaken->fetchColumn()) {
            throw new RuntimeException(__('invoice_number') . ' ' . $invoice_number . ': duplicate');
        }

        // The VAT status is a snapshot of the invoice, not of today's setting.
        $policy = crmInvoiceVatPolicy();
        $policy = !empty($existing_invoice['is_vat_payer'])
            ? ['payer' => true, 'rate' => $policy['payer'] ? $policy['rate'] : (float)get_setting('acc_vat_rate', '21')]
            : ['payer' => false, 'rate' => 0.0];
        $amounts = crmInvoiceAmountsFromCustomerTotal($total_amount, $policy);

        // Re-saving a paid invoice must keep its original payment date (it drives the finance period).
        $payment_date = ($status === 'paid') ? ($existing_invoice['payment_date'] ?: date('Y-m-d')) : null;

        $pdo->prepare(
            'UPDATE invoices SET invoice_number = ?, variable_symbol = ?, date_issue = ?, date_tax = ?, date_due = ?,
                 total_amount = ?, vat_amount = ?, notes = ?, status = ?, payment_method = ?, payment_date = ?
             WHERE id = ?'
        )->execute([
            $invoice_number, $invoice_number, $date_issue, $date_issue, $date_due,
            $amounts['total'], $amounts['vat'], $item_name, $status, $payment_method, $payment_date,
            $invoice_id,
        ]);

        $existing_item = $pdo->prepare('SELECT id FROM invoice_items WHERE invoice_id = ? ORDER BY id LIMIT 1');
        $existing_item->execute([$invoice_id]);
        $item_id = $existing_item->fetchColumn();
        if ($item_id) {
            $pdo->prepare('UPDATE invoice_items SET item_name = ?, quantity = 1, price = ?, vat_rate = ? WHERE id = ?')
                ->execute([$item_name, $amounts['net'], $amounts['rate'], $item_id]);
            // The express form edits a single-line document: extra lines would no longer add up to the total.
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ? AND id <> ?')->execute([$invoice_id, $item_id]);
        } else {
            $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, 'ks', ?, ?)")
                ->execute([$invoice_id, $item_name, $amounts['net'], $amounts['rate']]);
        }
        $action = 'updated';
    } else {
        // Empty or already used number -> next number of the shared, locked series.
        if ($invoice_number !== '') {
            $numberTaken->execute([$invoice_number, 0]);
            if ($numberTaken->fetchColumn()) {
                $invoice_number = '';
            }
        }
        if ($invoice_number === '') {
            $invoice_number = crmReserveInvoiceNumber($pdo);
        } else {
            crmAdvanceInvoiceCounterPast($pdo, $invoice_number);
        }

        $amounts = crmInvoiceAmountsFromCustomerTotal($total_amount);
        $pdo->prepare(
            "INSERT INTO invoices
                (order_id, customer_id, invoice_number, variable_symbol, date_issue, date_tax, date_due,
                 total_amount, vat_amount, is_vat_payer, currency, status, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, NOW())"
        )->execute([
            $order_id, $order['customer_id'], $invoice_number, $invoice_number, $date_issue, $date_issue, $date_due,
            $amounts['total'], $amounts['vat'], $amounts['payer'] ? 1 : 0, get_setting('currency', 'Kč'), $item_name,
        ]);
        $invoice_id = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, 'ks', ?, ?)")
            ->execute([$invoice_id, $item_name, $amounts['net'], $amounts['rate']]);
        $action = 'created';
    }

    // The invoice total is the customer charge; keep the order in step (binding revenue rule).
    $pdo->prepare('UPDATE orders SET final_cost = ? WHERE id = ?')->execute([$total_amount, $order_id]);
    $pdo->commit();

    api_json_exit([
        'success' => true,
        'id' => (int)$invoice_id,
        'invoice_number' => $invoice_number,
        'action' => $action,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Express Invoice Error: ' . $e->getMessage());
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
