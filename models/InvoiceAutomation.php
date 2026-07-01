<?php

require_once __DIR__ . '/MyInvoiceApiClient.php';

function normalizeMyInvoiceCurrency(string $currency): string {
    $currency = trim($currency);
    if ($currency === 'Kč' || strtolower($currency) === 'kc') {
        return 'CZK';
    }
    return strtoupper($currency ?: 'CZK');
}

function normalizeMyInvoicePaymentMethod(string $method): string {
    return in_array($method, ['bank_transfer', 'card', 'cash', 'other'], true) ? $method : 'other';
}

function myInvoiceConfig(string $envKey, string $settingKey, string $default = ''): string {
    $envValue = getenv($envKey);
    if ($envValue !== false && $envValue !== '') {
        return (string)$envValue;
    }
    return (string)get_setting($settingKey, $default);
}

function createLocalInvoiceForCompletedOrder(PDO $pdo, int $orderId, $finalCost = null): array {
    $existing = $pdo->prepare('SELECT id FROM invoices WHERE order_id = ? LIMIT 1');
    $existing->execute([$orderId]);
    $existingId = $existing->fetchColumn();
    if ($existingId) {
        return ['success' => true, 'id' => (int)$existingId, 'created' => false];
    }

    $stmt = $pdo->prepare('SELECT o.*, c.first_name, c.last_name, c.company FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    $price = $finalCost;
    if ($price === null || $price === '') {
        $price = ($order['final_cost'] !== null && $order['final_cost'] !== '') ? $order['final_cost'] : $order['estimated_cost'];
    }
    $price = (float)$price;
    if ($price <= 0) {
        return ['success' => false, 'error' => 'Final cost is missing or zero'];
    }

    $prefix = get_setting('acc_invoice_prefix', date('Y'));
    $nextNumber = (int)get_setting('acc_invoice_next_number', '1');
    $lock = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'acc_invoice_next_number' FOR UPDATE");
    $lock->execute();
    $lockedValue = $lock->fetchColumn();
    if ($lockedValue !== false) {
        $nextNumber = max(1, (int)$lockedValue);
    }

    do {
        $invoiceNumber = $prefix . str_pad((string)$nextNumber, 4, '0', STR_PAD_LEFT);
        $dupe = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
        $dupe->execute([$invoiceNumber]);
        if (!$dupe->fetchColumn()) {
            break;
        }
        $nextNumber++;
    } while (true);

    $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('acc_invoice_next_number', ?)")
        ->execute([(string)($nextNumber + 1)]);

    $isVatPayer = get_setting('acc_is_vat_payer', '0') == '1';
    $vatRate = (float)get_setting('acc_vat_rate', '21');
    $vatAmount = $isVatPayer ? $price * ($vatRate / 100) : 0;
    $currency = get_setting('currency', 'Kč');
    $today = date('Y-m-d');
    $due = date('Y-m-d', strtotime('+14 days'));

    $insert = $pdo->prepare("
        INSERT INTO invoices (
            invoice_number, variable_symbol, customer_id, order_id, date_issue, date_tax, date_due,
            total_amount, vat_amount, is_vat_payer, status, payment_method, currency, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', 'bank_transfer', ?, ?)
    ");
    $insert->execute([
        $invoiceNumber,
        $invoiceNumber,
        (int)$order['customer_id'],
        $orderId,
        $today,
        $today,
        $due,
        $isVatPayer ? ($price + $vatAmount) : $price,
        $vatAmount,
        $isVatPayer ? 1 : 0,
        $currency,
        'Auto-created from order #' . $orderId,
    ]);
    $invoiceId = (int)$pdo->lastInsertId();

    $itemName = trim('Oprava ' . ($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));
    if ($itemName === 'Oprava') {
        $itemName = 'Oprava zakazky #' . $orderId;
    }

    $item = $pdo->prepare('INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, 1, ?, ?, ?)');
    $item->execute([$invoiceId, $itemName, 'ks', $price, $isVatPayer ? $vatRate : 0]);

    return ['success' => true, 'id' => $invoiceId, 'created' => true];
}

/**
 * Cancel auto-created, still-unpaid invoices for an order.
 *
 * Called when an order leaves a "repaired" state (Ready/Issued) — e.g. reverted
 * to In Repair or moved to an unrepaired terminal status — so that a stale
 * invoice does not remain issued for a job that is back in progress or aborted.
 * Only invoices created by the automation (notes start with "Auto-created") and
 * not yet paid are touched; manually created or paid invoices are preserved.
 */
function cancelAutoInvoicesForOrder(PDO $pdo, int $orderId): int {
    $stmt = $pdo->prepare(
        "SELECT id FROM invoices
         WHERE order_id = ?
           AND status IN ('issued', 'draft')
           AND notes LIKE 'Auto-created%'
         FOR UPDATE"
    );
    $stmt->execute([$orderId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        return 0;
    }

    $cancel = $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?");
    foreach ($ids as $invoiceId) {
        $cancel->execute([(int)$invoiceId]);
    }

    return count($ids);
}

function syncInvoiceToMyInvoice(PDO $pdo, int $invoiceId): array {
    if (myInvoiceConfig('MYINVOICE_ENABLED', 'myinvoice_enabled', '1') !== '1') {
        return ['success' => true, 'skipped' => true, 'message' => 'MyInvoice sync disabled'];
    }

    $client = new MyInvoiceApiClient();
    if (!$client->isConfigured()) {
        return markMyInvoiceSyncFailure($pdo, $invoiceId, 'MyInvoice API token is not configured.');
    }

    $invoice = loadLocalInvoiceForMyInvoice($pdo, $invoiceId);
    if (!$invoice) {
        return ['success' => false, 'error' => 'Invoice not found'];
    }

    if (!empty($invoice['myinvoice_invoice_id'])) {
        return ['success' => true, 'id' => (int)$invoice['myinvoice_invoice_id'], 'created' => false];
    }

    try {
        $myInvoiceClientId = ensureMyInvoiceClient($pdo, $client, $invoice);
        $payload = buildMyInvoicePayload($client, $invoice, $myInvoiceClientId);
        $created = $client->createInvoice($payload);
        $external = (myInvoiceConfig('MYINVOICE_AUTO_ISSUE', 'myinvoice_auto_issue', '1') === '1')
            ? $client->issueInvoice((int)$created['id'])
            : $created;

        $status = $external['status'] ?? $created['status'] ?? 'draft';
        $update = $pdo->prepare("
            UPDATE invoices
            SET myinvoice_invoice_id = ?, myinvoice_status = ?, myinvoice_synced_at = NOW(), myinvoice_sync_error = NULL
            WHERE id = ?
        ");
        $update->execute([(int)$external['id'], $status, $invoiceId]);

        return ['success' => true, 'id' => (int)$external['id'], 'status' => $status, 'created' => true];
    } catch (Throwable $e) {
        return markMyInvoiceSyncFailure($pdo, $invoiceId, $e->getMessage());
    }
}

function loadLocalInvoiceForMyInvoice(PDO $pdo, int $invoiceId): ?array {
    $stmt = $pdo->prepare("
        SELECT i.*, c.id AS local_customer_id, c.customer_type, c.first_name, c.last_name, c.company, c.ico, c.dic,
               c.phone, c.email, c.address, c.myinvoice_client_id
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return null;
    }

    $items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
    $items->execute([$invoiceId]);
    $invoice['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

    return $invoice;
}

function ensureMyInvoiceClient(PDO $pdo, MyInvoiceApiClient $client, array $invoice): int {
    $cachedId = (int)($invoice['myinvoice_client_id'] ?? 0);
    if ($cachedId > 0) {
        try {
            $client->getClient($cachedId);
            return $cachedId;
        } catch (MyInvoiceApiException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    $companyName = trim($invoice['company'] ?: ($invoice['first_name'] . ' ' . $invoice['last_name']));
    $ico = trim((string)($invoice['ico'] ?? ''));
    $query = $ico !== '' ? $ico : $companyName;
    if ($query !== '') {
        foreach ($client->findClients($query) as $candidate) {
            $candidateIco = trim((string)($candidate['ic'] ?? $candidate['ico'] ?? ''));
            $candidateName = trim((string)($candidate['company_name'] ?? ''));
            if (($ico !== '' && $candidateIco === $ico) || strcasecmp($candidateName, $companyName) === 0) {
                cacheMyInvoiceClientId($pdo, (int)$invoice['local_customer_id'], (int)$candidate['id']);
                return (int)$candidate['id'];
            }
        }
    }

    $payload = buildMyInvoiceClientPayload($invoice);
    $created = $client->createClient($payload);
    cacheMyInvoiceClientId($pdo, (int)$invoice['local_customer_id'], (int)$created['id']);

    return (int)$created['id'];
}

function cacheMyInvoiceClientId(PDO $pdo, int $customerId, int $myInvoiceClientId): void {
    $stmt = $pdo->prepare('UPDATE customers SET myinvoice_client_id = ? WHERE id = ?');
    $stmt->execute([$myInvoiceClientId, $customerId]);
}

function buildMyInvoiceClientPayload(array $invoice): array {
    $companyName = trim($invoice['company'] ?: ($invoice['first_name'] . ' ' . $invoice['last_name']));
    if ($companyName === '') {
        $companyName = 'CRM customer #' . (int)$invoice['local_customer_id'];
    }

    $email = trim((string)($invoice['email'] ?? ''));
    if ($email === '') {
        $email = trim((string)(getenv('MYINVOICE_FALLBACK_EMAIL') ?: get_setting('myinvoice_fallback_email', '')));
    }
    if ($email === '') {
        throw new RuntimeException('Customer email is required by MyInvoice API.');
    }

    $address = splitAddressForMyInvoice((string)($invoice['address'] ?? ''));

    return [
        'company_name' => $companyName,
        'display_name' => $companyName,
        'ic' => trim((string)($invoice['ico'] ?? '')) ?: null,
        'dic' => trim((string)($invoice['dic'] ?? '')) ?: null,
        'street' => $address['street'],
        'city' => $address['city'],
        'zip' => $address['zip'],
        'country_id' => (int)(getenv('MYINVOICE_DEFAULT_COUNTRY_ID') ?: get_setting('myinvoice_default_country_id', '1')),
        'main_email' => $email,
        'phone' => trim((string)($invoice['phone'] ?? '')) ?: null,
        'language' => getenv('MYINVOICE_DEFAULT_LANGUAGE') ?: get_setting('myinvoice_default_language', 'cs'),
        'reverse_charge' => false,
        'payment_due_default' => (int)(getenv('MYINVOICE_PAYMENT_DUE_DAYS') ?: get_setting('myinvoice_payment_due_days', '14')),
    ];
}

function splitAddressForMyInvoice(string $address): array {
    $address = trim(preg_replace('/\s+/u', ' ', $address) ?: $address);
    $defaultStreet = getenv('MYINVOICE_DEFAULT_STREET') ?: get_setting('myinvoice_default_street', '-');
    $defaultCity = getenv('MYINVOICE_DEFAULT_CITY') ?: get_setting('myinvoice_default_city', 'Praha');
    $defaultZip = getenv('MYINVOICE_DEFAULT_ZIP') ?: get_setting('myinvoice_default_zip', '11000');

    if ($address === '') {
        return ['street' => $defaultStreet, 'city' => $defaultCity, 'zip' => $defaultZip];
    }

    $zip = $defaultZip;
    $city = $defaultCity;
    $street = $address;
    if (preg_match('/^(.*?)[,\s]+(\d{3}\s?\d{2})\s+(.+)$/u', $address, $matches)) {
        $street = trim($matches[1], " \t\n\r\0\x0B,");
        $zip = preg_replace('/\s+/', '', $matches[2]);
        $city = trim($matches[3], " \t\n\r\0\x0B,");
    }

    return [
        'street' => $street !== '' ? $street : $defaultStreet,
        'city' => $city !== '' ? $city : $defaultCity,
        'zip' => $zip !== '' ? $zip : $defaultZip,
    ];
}

function buildMyInvoicePayload(MyInvoiceApiClient $client, array $invoice, int $myInvoiceClientId): array {
    $vatRateId = resolveMyInvoiceVatRateId($client, (float)($invoice['items'][0]['vat_rate'] ?? 0));
    $items = [];
    foreach ($invoice['items'] as $index => $item) {
        $items[] = [
            'description' => (string)$item['item_name'],
            'quantity' => (float)($item['quantity'] ?? 1),
            'unit' => (string)($item['unit'] ?? 'ks'),
            'unit_price_without_vat' => (float)($item['price'] ?? 0),
            'vat_rate_id' => $vatRateId,
            'order_index' => $index + 1,
        ];
    }

    if (!$items) {
        throw new RuntimeException('Invoice has no items.');
    }

    return [
        'client_id' => $myInvoiceClientId,
        'invoice_type' => $invoice['invoice_type'] === 'credit_note' ? 'credit_note' : 'invoice',
        'issue_date' => $invoice['date_issue'],
        'tax_date' => $invoice['date_tax'],
        'due_date' => $invoice['date_due'],
        'currency' => normalizeMyInvoiceCurrency((string)$invoice['currency']),
        'language' => getenv('MYINVOICE_DEFAULT_LANGUAGE') ?: get_setting('myinvoice_default_language', 'cs'),
        'varsymbol' => $invoice['variable_symbol'] ?: $invoice['invoice_number'],
        'payment_method' => normalizeMyInvoicePaymentMethod((string)$invoice['payment_method']),
        'note_below_items' => trim((string)($invoice['notes'] ?? '')) ?: null,
        'items' => $items,
    ];
}

function resolveMyInvoiceVatRateId(MyInvoiceApiClient $client, float $vatRate): int {
    $configured = getenv('MYINVOICE_DEFAULT_VAT_RATE_ID') ?: get_setting('myinvoice_default_vat_rate_id', '');
    try {
        $rates = $client->getVatRates();
        foreach ($rates as $rate) {
            $percent = $rate['rate_percent'] ?? $rate['rate'] ?? $rate['percent'] ?? $rate['value'] ?? null;
            if ($percent !== null && abs((float)$percent - $vatRate) < 0.001) {
                return (int)$rate['id'];
            }
        }
    } catch (Throwable $e) {
        if ($configured === '') {
            throw $e;
        }
    }

    if ($configured !== '') {
        return (int)$configured;
    }

    throw new RuntimeException('Cannot resolve MyInvoice VAT rate ID for rate ' . $vatRate . '.');
}

function markMyInvoiceSyncFailure(PDO $pdo, int $invoiceId, string $error): array {
    $stmt = $pdo->prepare('UPDATE invoices SET myinvoice_sync_error = ?, myinvoice_synced_at = NOW() WHERE id = ?');
    $stmt->execute([$error, $invoiceId]);
    error_log('MyInvoice sync failed for invoice #' . $invoiceId . ': ' . $error);

    return ['success' => false, 'error' => $error];
}
