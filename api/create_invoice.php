<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../models/InvoicePolicy.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'create_invoice',
]);

$order_id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$invoice_number = trim((string)($_POST['invoice_number'] ?? ''));
$variable_symbol = trim((string)($_POST['variable_symbol'] ?? ''));
$date_issue = (string)($_POST['date_issue'] ?? date('Y-m-d'));
$date_tax = (string)($_POST['date_tax'] ?? $date_issue);
$date_due = (string)($_POST['date_due'] ?? date('Y-m-d', strtotime('+14 days')));

$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (
    !$order_id
    || mb_strlen($invoice_number) > 64
    || mb_strlen($variable_symbol) > 64
    || !$isValidDate($date_issue) || !$isValidDate($date_tax) || !$isValidDate($date_due)
    || $date_due < $date_issue
) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

// Lines are amounts the customer pays; the invoice total is their sum (not a separate client field).
$items = [];
foreach ((array)($_POST['item_name'] ?? []) as $index => $name) {
    $name = trim((string)$name);
    if ($name === '') {
        continue;
    }
    $price = $_POST['item_price'][$index] ?? null;
    if (!is_numeric($price) || !is_finite((float)$price) || (float)$price < 0 || mb_strlen($name) > 255) {
        api_json_exit(['success' => false, 'message' => __('missing_data')]);
    }
    $items[] = ['name' => $name, 'price' => round((float)$price, 2)];
}
if ($items === [] || count($items) > 100) {
    api_json_exit(['success' => false, 'message' => __('missing_data')]);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT customer_id FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$order_id]);
    $customer_id = $stmt->fetchColumn();
    if (!$customer_id) {
        throw new RuntimeException(__('not_found'));
    }

    if ($invoice_number !== '') {
        $taken = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $taken->execute([$invoice_number]);
        if ($taken->fetchColumn()) {
            $invoice_number = '';
        }
    }
    if ($invoice_number === '') {
        $invoice_number = crmReserveInvoiceNumber($pdo);
    } else {
        crmAdvanceInvoiceCounterPast($pdo, $invoice_number);
    }
    if ($variable_symbol === '') {
        $variable_symbol = $invoice_number;
    }

    $policy = crmInvoiceVatPolicy();
    $lines = [];
    $total = 0.0;
    $vat = 0.0;
    foreach ($items as $item) {
        $line = crmInvoiceAmountsFromCustomerTotal($item['price'], $policy);
        $lines[] = ['name' => $item['name'], 'net' => $line['net'], 'rate' => $line['rate']];
        $total += $line['total'];
        $vat += $line['vat'];
    }

    $pdo->prepare(
        "INSERT INTO invoices (invoice_number, variable_symbol, order_id, customer_id, date_issue, date_tax, date_due,
             total_amount, is_vat_payer, vat_amount, currency, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued')"
    )->execute([
        $invoice_number, $variable_symbol, $order_id, $customer_id, $date_issue, $date_tax, $date_due,
        round($total, 2), $policy['payer'] ? 1 : 0, round($vat, 2), get_setting('currency', 'Kč'),
    ]);
    $invoice_id = (int)$pdo->lastInsertId();

    $stmt_item = $pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, 'ks', ?, ?)");
    foreach ($lines as $line) {
        $stmt_item->execute([$invoice_id, $line['name'], $line['net'], $line['rate']]);
    }

    $pdo->commit();
    api_json_exit(['success' => true, 'message' => 'Invoice created', 'id' => $invoice_id, 'invoice_number' => $invoice_number]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)]);
}
