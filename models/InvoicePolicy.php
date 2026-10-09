<?php
/**
 * Single source of truth for invoice VAT treatment and invoice numbering.
 *
 * VAT rule (binding, see root AGENTS.md):
 * - The customer charge (order final_cost / invoice total) is the amount the customer pays.
 * - Non-VAT-payer (acc_is_vat_payer = 0, the current business setup): VAT is 0 and
 *   item prices equal the amounts paid.
 * - VAT payer: total_amount stays the amount paid (gross); VAT is extracted from it and
 *   invoice_items.price is stored WITHOUT VAT, which is what print_invoice.php and the
 *   MyInvoice sync (unit_price_without_vat) expect.
 * - The VAT status is snapshotted on the invoice (invoices.is_vat_payer); documents are
 *   rendered from that snapshot, never from the current setting.
 */

/**
 * @return array{payer:bool,rate:float}
 */
function crmInvoiceVatPolicy(): array
{
    $payer = get_setting('acc_is_vat_payer', '0') === '1';
    $rate = $payer ? max(0.0, min(100.0, (float)get_setting('acc_vat_rate', '21'))) : 0.0;
    return ['payer' => $payer, 'rate' => $rate];
}

/**
 * Split the amount the customer pays into net / VAT under the current policy.
 *
 * @return array{total:float,net:float,vat:float,payer:bool,rate:float}
 */
function crmInvoiceAmountsFromCustomerTotal(float $customerTotal, ?array $policy = null): array
{
    $policy = $policy ?? crmInvoiceVatPolicy();
    $total = round($customerTotal, 2);
    if (!$policy['payer'] || $policy['rate'] <= 0) {
        return ['total' => $total, 'net' => $total, 'vat' => 0.0, 'payer' => $policy['payer'], 'rate' => $policy['payer'] ? $policy['rate'] : 0.0];
    }
    $net = round($total / (1 + $policy['rate'] / 100), 2);
    return ['total' => $total, 'net' => $net, 'vat' => round($total - $net, 2), 'payer' => true, 'rate' => $policy['rate']];
}

/**
 * Formats a regular invoice number from the configured prefix and a sequence.
 */
function crmFormatInvoiceNumber(int $sequence, ?string $prefix = null): string
{
    $prefix = $prefix ?? (string)get_setting('acc_invoice_prefix', date('Y'));
    return $prefix . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
}

/**
 * Reserve the next regular invoice number. Must run inside the caller's transaction:
 * the counter row is locked FOR UPDATE, so concurrent creators never get the same number.
 */
function crmReserveInvoiceNumber(PDO $pdo): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Invoice numbers must be reserved inside a transaction.');
    }
    $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('acc_invoice_next_number', '1')
         ON DUPLICATE KEY UPDATE setting_key = setting_key"
    )->execute();
    $lock = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'acc_invoice_next_number' FOR UPDATE");
    $lock->execute();
    $next = max(1, (int)$lock->fetchColumn());

    $prefix = (string)get_setting('acc_invoice_prefix', date('Y'));
    $dupe = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
    do {
        $number = crmFormatInvoiceNumber($next, $prefix);
        $dupe->execute([$number]);
        $taken = (bool)$dupe->fetchColumn();
        if ($taken) {
            $next++;
        }
    } while ($taken);

    $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'acc_invoice_next_number'")
        ->execute([(string)($next + 1)]);
    return $number;
}

/**
 * A manually typed number in the regular series (prefix + digits) moves the counter past it,
 * so the next reserved number cannot collide. Call inside the saving transaction.
 */
function crmAdvanceInvoiceCounterPast(PDO $pdo, string $invoiceNumber): void
{
    $prefix = (string)get_setting('acc_invoice_prefix', date('Y'));
    if ($prefix === '' || !str_starts_with($invoiceNumber, $prefix)) {
        return;
    }
    $suffix = substr($invoiceNumber, strlen($prefix));
    if ($suffix === '' || !ctype_digit($suffix)) {
        return;
    }
    $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES ('acc_invoice_next_number', ?)
         ON DUPLICATE KEY UPDATE setting_value = GREATEST(CAST(setting_value AS UNSIGNED), CAST(VALUES(setting_value) AS UNSIGNED))"
    )->execute([(string)((int)$suffix + 1)]);
}

/**
 * Next number to pre-fill in the UI (not reserved; the save path re-checks uniqueness).
 */
function crmSuggestInvoiceNumber(PDO $pdo): string
{
    $next = max(1, (int)get_setting('acc_invoice_next_number', '1'));
    $prefix = (string)get_setting('acc_invoice_prefix', date('Y'));
    $dupe = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = ? LIMIT 1');
    for ($i = 0; $i < 1000; $i++) {
        $number = crmFormatInvoiceNumber($next + $i, $prefix);
        $dupe->execute([$number]);
        if (!$dupe->fetchColumn()) {
            return $number;
        }
    }
    return crmFormatInvoiceNumber($next, $prefix);
}
