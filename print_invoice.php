<?php
/**
 * A4 invoice print — Czech commercial / tax document.
 *
 * Neplátce DPH (Rich Technologies s.r.o. and similar):
 *   title "FAKTURA" — not a DPH daňový doklad; includes OR entry + neplátce note.
 * Plátce DPH:
 *   title "FAKTURA – DAŇOVÝ DOKLAD" with § 29 ZDPH fields (DUZP, DPH rozpis).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/qr_code.php';

if (!isset($_SESSION['user_id'])) {
    die(__('unauthorized'));
}
if (!hasPermission('admin_access')) {
    http_response_code(403);
    die(__('unauthorized'));
}
if (!isset($_GET['id'])) {
    die(__('missing_id'));
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare(
    "SELECT i.*,
            c.first_name, c.last_name, c.phone, c.email AS customer_email,
            c.address, c.company, c.ico, c.dic,
            o.device_brand, o.device_model, o.serial_number, o.id AS order_ref
     FROM invoices i
     JOIN customers c ON i.customer_id = c.id
     LEFT JOIN orders o ON i.order_id = o.id
     WHERE i.id = ?"
);
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die(__('print_not_found'));
}

$stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC');
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$parent_invoice = null;
if (!empty($invoice['parent_id'])) {
    $p = $pdo->prepare('SELECT invoice_number, date_issue FROM invoices WHERE id = ?');
    $p->execute([(int)$invoice['parent_id']]);
    $parent_invoice = $p->fetch(PDO::FETCH_ASSOC) ?: null;
}

// VAT status is the invoice snapshot only: today's setting must not re-price an old document.
$is_vat_payer = !empty($invoice['is_vat_payer']);
$is_credit = (($invoice['invoice_type'] ?? '') === 'credit_note');

$company_name = trim((string)get_setting('acc_company_name', 'Rich Technologies s.r.o.'));
$company_address = trim((string)get_setting('acc_address'));
$company_ico = trim((string)get_setting('acc_ico'));
$company_dic = trim((string)get_setting('acc_dic'));
$company_phone = trim((string)get_setting('company_phone'));
$company_email = trim((string)get_setting('acc_email', get_setting('company_email', '')));
$trade_register = trim((string)get_setting('acc_trade_register'));
$bank_name = trim((string)get_setting('acc_bank_name'));
$bank_account = trim((string)get_setting('acc_bank_account'));
$iban = preg_replace('/\s+/', '', (string)get_setting('acc_iban'));
$swift = trim((string)get_setting('acc_swift'));

$currency_raw = trim((string)($invoice['currency'] ?: get_setting('currency', 'Kč')));
// Normalize legacy "Kc" to Czech crown symbol used on printouts.
$currency = in_array(mb_strtolower($currency_raw), ['kc', 'czk', 'kč'], true) ? 'Kč' : $currency_raw;
$currency_iso = in_array(mb_strtolower($currency_raw), ['kc', 'czk', 'kč'], true) ? 'CZK' : strtoupper($currency_raw);

$cust_name = trim((string)(
    $invoice['cust_name_override']
        ?: ($invoice['company'] ?: trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? '')))
));
$cust_address = trim((string)($invoice['cust_address_override'] ?: ($invoice['address'] ?? '')));
$cust_ico = trim((string)($invoice['cust_ico_override'] ?: ($invoice['ico'] ?? '')));
$cust_dic = trim((string)($invoice['cust_dic_override'] ?: ($invoice['dic'] ?? '')));
$cust_phone = trim((string)($invoice['phone'] ?? ''));
$cust_email = trim((string)($invoice['customer_email'] ?? ''));

if ($is_credit) {
    $doc_title = $is_vat_payer ? 'OPRAVNÝ DAŇOVÝ DOKLAD' : 'DOBROPIS / OPRAVNÝ DOKLAD';
} elseif ($is_vat_payer) {
    $doc_title = 'FAKTURA – DAŇOVÝ DOKLAD';
} else {
    // Neplátce DPH must not label the document as a DPH tax document.
    $doc_title = 'FAKTURA';
}

$payment_labels = [
    'bank_transfer' => 'Bankovní převod',
    'cash' => 'Hotově',
    'card' => 'Kartou',
    'cod' => 'Dobírka',
];
$payment_method = $payment_labels[$invoice['payment_method'] ?? ''] ?? (string)($invoice['payment_method'] ?? '—');

$fmt_date = static function ($value): string {
    if (empty($value) || $value === '0000-00-00') {
        return '—';
    }
    $ts = strtotime((string)$value);
    return $ts ? date('d.m.Y', $ts) : '—';
};
$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$money = static function ($amount) use ($currency): string {
    return number_format((float)$amount, 2, ',', "\u{00A0}") . "\u{00A0}" . $currency;
};

// Line totals + VAT summary (prices in DB are without VAT when is_vat_payer; with VAT when not).
$vat_summary = [];
$lines = [];
$base_total = 0.0;
$vat_total = 0.0;
$gross_total = 0.0;

if (empty($items)) {
    $fallback_name = trim((string)($invoice['notes'] ?: 'Oprava / servisní služba'));
    $items = [[
        'item_name' => $fallback_name,
        'quantity' => 1,
        'unit' => 'ks',
        'price' => (float)$invoice['total_amount'],
        'vat_rate' => 0,
    ]];
}

foreach ($items as $item) {
    $qty = (float)$item['quantity'];
    $unit_price = (float)$item['price'];
    $line_base = $qty * $unit_price;
    $rate = $is_vat_payer ? (float)($item['vat_rate'] ?? 0) : 0.0;
    $line_vat = $is_vat_payer ? round($line_base * ($rate / 100), 2) : 0.0;
    $line_gross = $is_vat_payer ? ($line_base + $line_vat) : $line_base;

    $base_total += $line_base;
    $vat_total += $line_vat;
    $gross_total += $line_gross;

    if ($is_vat_payer) {
        $key = number_format($rate, 2, '.', '');
        if (!isset($vat_summary[$key])) {
            $vat_summary[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
        }
        $vat_summary[$key]['base'] += $line_base;
        $vat_summary[$key]['vat'] += $line_vat;
    }

    $lines[] = [
        'name' => (string)$item['item_name'],
        'qty' => $qty,
        'unit' => (string)($item['unit'] ?: 'ks'),
        'unit_price' => $unit_price,
        'rate' => $rate,
        'base' => $line_base,
        'vat' => $line_vat,
        'gross' => $line_gross,
    ];
}

// Prefer stored invoice total (source of truth for accounting).
$display_total = (float)$invoice['total_amount'];
if (abs($display_total) < 0.00001 && abs($gross_total) > 0.00001) {
    $display_total = $gross_total;
}

// Czech payment QR (SPD) for bank transfer — optional convenience, not a legal requirement.
$qr_spd = '';
if (($invoice['payment_method'] ?? '') === 'bank_transfer' && $iban !== '' && abs($display_total) > 0) {
    $parts = [
        'SPD*1.0',
        'ACC:' . $iban,
        'AM:' . number_format(abs($display_total), 2, '.', ''),
        'CC:' . $currency_iso,
    ];
    if (!empty($invoice['variable_symbol'])) {
        $parts[] = 'X-VS:' . preg_replace('/\D+/', '', (string)$invoice['variable_symbol']);
    }
    $msg = 'Faktura ' . ($invoice['invoice_number'] ?? '');
    $parts[] = 'MSG:' . substr(preg_replace('/[^\p{L}\p{N}\s\.\-]/u', '', $msg), 0, 60);
    $qr_spd = implode('*', $parts);
}

$page_title = $doc_title . ' ' . ($invoice['invoice_number'] ?? '');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $h($page_title); ?></title>
    <style>
        :root {
            --ink: #111;
            --muted: #555;
            --line: #222;
            --soft: #f3f3f3;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.35;
            color: var(--ink);
            margin: 0;
            padding: 0;
            background: #e9e9e9;
        }
        .toolbar {
            text-align: center;
            padding: 16px;
            background: #1b1f26;
        }
        .toolbar button, .toolbar a {
            display: inline-block;
            margin: 0 6px;
            padding: 10px 18px;
            border: 0;
            border-radius: 6px;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }
        .toolbar button { background: #0d6efd; }
        .toolbar a { background: #6c757d; }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 12mm auto;
            padding: 14mm 14mm 12mm;
            background: #fff;
            box-shadow: 0 2px 16px rgba(0,0,0,.12);
            position: relative;
        }
        .doc-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-bottom: 2px solid var(--line);
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .doc-title {
            font-size: 20pt;
            font-weight: 800;
            letter-spacing: 0.02em;
            margin: 0 0 4px;
            text-transform: uppercase;
        }
        .doc-number { font-size: 13pt; font-weight: 700; }
        .doc-meta { text-align: right; font-size: 10pt; }
        .doc-meta strong { display: inline-block; min-width: 9em; text-align: left; margin-right: 4px; color: var(--muted); font-weight: 600; }
        .badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 8px;
            border: 1px solid var(--line);
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
        }
        .parties {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .parties td {
            width: 50%;
            vertical-align: top;
            border: 1px solid var(--line);
            padding: 10px 12px;
        }
        .party-label {
            font-size: 8.5pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .party-name { font-size: 12pt; font-weight: 800; margin-bottom: 4px; }
        .party-block { font-size: 10pt; }
        .party-block .k { color: var(--muted); }
        .dates {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .dates td {
            width: 25%;
            border: 1px solid var(--line);
            padding: 8px 10px;
            vertical-align: top;
        }
        .dates .lbl {
            display: block;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 3px;
        }
        .dates .val { font-weight: 700; font-size: 10.5pt; }
        .items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .items th {
            background: var(--soft);
            border: 1px solid var(--line);
            padding: 7px 8px;
            font-size: 8.5pt;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            text-align: left;
        }
        .items td {
            border: 1px solid var(--line);
            padding: 7px 8px;
            vertical-align: top;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals-wrap {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 14px;
        }
        .vat-box, .pay-box {
            flex: 1;
            border: 1px solid var(--line);
            padding: 10px 12px;
            min-height: 90px;
        }
        .vat-box h3, .pay-box h3, .legal h3 {
            margin: 0 0 8px;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
        }
        .vat-table { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        .vat-table th, .vat-table td { border-bottom: 1px solid #ddd; padding: 4px 0; }
        .vat-table th { text-align: left; color: var(--muted); font-weight: 600; }
        .grand {
            width: 42%;
            border: 2px solid var(--line);
            padding: 12px 14px;
            background: var(--soft);
            text-align: right;
        }
        .grand .lbl {
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .grand .val { font-size: 18pt; font-weight: 800; }
        .grand .sub { font-size: 9.5pt; margin-top: 4px; color: var(--muted); }
        .legal {
            border-top: 1px solid #ddd;
            padding-top: 10px;
            margin-top: 8px;
            font-size: 9.5pt;
            color: #333;
        }
        .legal p { margin: 0 0 6px; }
        .legal strong.nonvat {
            display: inline-block;
            border: 1px solid var(--line);
            padding: 4px 8px;
            margin-bottom: 6px;
        }
        .or-line {
            font-size: 8.5pt;
            color: var(--muted);
            margin-top: 8px;
            line-height: 1.3;
        }
        .sign-row {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            margin-top: 28px;
        }
        .sign-box { width: 46%; }
        .sign-line {
            margin-top: 48px;
            border-top: 1px solid var(--line);
            padding-top: 6px;
            font-size: 9pt;
            color: var(--muted);
            text-align: center;
        }
        .qr-wrap { text-align: center; margin-top: 8px; }
        .qr-wrap img { width: 96px; height: 96px; }
        .qr-caption { font-size: 8pt; color: var(--muted); margin-top: 2px; }
        .page-foot {
            position: absolute;
            left: 14mm;
            right: 14mm;
            bottom: 8mm;
            font-size: 8pt;
            color: var(--muted);
            display: flex;
            justify-content: space-between;
            border-top: 1px solid #ddd;
            padding-top: 4px;
        }
        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page {
                margin: 0;
                box-shadow: none;
                width: auto;
                min-height: auto;
                padding: 10mm 12mm;
            }
            .page-foot { position: fixed; }
            @page { size: A4; margin: 8mm; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print">
    <button type="button" data-print-action="print"><?php echo __('print_btn'); ?></button>
    <a href="accounting.php"><?php echo __('back'); ?></a>
</div>

<div class="page">
    <div class="doc-head">
        <div>
            <h1 class="doc-title"><?php echo $h($doc_title); ?></h1>
            <div class="doc-number">číslo: <?php echo $h($invoice['invoice_number']); ?></div>
            <?php if (!$is_vat_payer): ?>
                <div class="badge">Dodavatel není plátcem DPH</div>
            <?php endif; ?>
            <?php if ($is_credit && $parent_invoice): ?>
                <div style="margin-top: 6px; font-size: 10pt;">
                    Oprava k dokladu č. <strong><?php echo $h($parent_invoice['invoice_number']); ?></strong>
                    <?php if (!empty($parent_invoice['date_issue'])): ?>
                        ze dne <?php echo $h($fmt_date($parent_invoice['date_issue'])); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="doc-meta">
            <?php if (!empty($invoice['variable_symbol'])): ?>
                <div><strong>Variabilní symbol:</strong> <?php echo $h($invoice['variable_symbol']); ?></div>
            <?php endif; ?>
            <div><strong>Konstantní symbol:</strong> 0308</div>
            <div><strong>Měna:</strong> <?php echo $h($currency_iso); ?> (<?php echo $h($currency); ?>)</div>
            <?php if (!empty($invoice['order_id'])): ?>
                <div><strong>Zakázka:</strong> #<?php echo (int)$invoice['order_id']; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <table class="parties">
        <tr>
            <td>
                <div class="party-label">Dodavatel</div>
                <div class="party-name"><?php echo $h($company_name !== '' ? $company_name : '—'); ?></div>
                <div class="party-block">
                    <?php if ($company_address !== ''): ?>
                        <div><?php echo nl2br($h($company_address)); ?></div>
                    <?php endif; ?>
                    <?php if ($company_ico !== ''): ?>
                        <div><span class="k">IČO:</span> <?php echo $h($company_ico); ?></div>
                    <?php endif; ?>
                    <?php if ($is_vat_payer && $company_dic !== ''): ?>
                        <div><span class="k">DIČ:</span> <?php echo $h($company_dic); ?></div>
                    <?php endif; ?>
                    <?php if ($company_phone !== ''): ?>
                        <div><span class="k">Tel:</span> <?php echo $h($company_phone); ?></div>
                    <?php endif; ?>
                    <?php if ($company_email !== ''): ?>
                        <div><span class="k">E-mail:</span> <?php echo $h($company_email); ?></div>
                    <?php endif; ?>
                    <?php if ($trade_register !== ''): ?>
                        <div class="or-line"><?php echo $h($trade_register); ?></div>
                    <?php elseif (!$is_vat_payer): ?>
                        <div class="or-line">
                            Doplňte zápis v obchodním rejstříku v nastavení účetnictví
                            (např. „Společnost zapsaná v obchodním rejstříku vedeném …, oddíl C, vložka …“).
                        </div>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <div class="party-label">Odběratel</div>
                <div class="party-name"><?php echo $h($cust_name !== '' ? $cust_name : '—'); ?></div>
                <div class="party-block">
                    <?php if ($cust_address !== ''): ?>
                        <div><?php echo nl2br($h($cust_address)); ?></div>
                    <?php endif; ?>
                    <?php if ($cust_ico !== ''): ?>
                        <div><span class="k">IČO:</span> <?php echo $h($cust_ico); ?></div>
                    <?php endif; ?>
                    <?php if ($cust_dic !== ''): ?>
                        <div><span class="k">DIČ:</span> <?php echo $h($cust_dic); ?></div>
                    <?php endif; ?>
                    <?php if ($cust_phone !== ''): ?>
                        <div><span class="k">Tel:</span> <?php echo $h($cust_phone); ?></div>
                    <?php endif; ?>
                    <?php if ($cust_email !== ''): ?>
                        <div><span class="k">E-mail:</span> <?php echo $h($cust_email); ?></div>
                    <?php endif; ?>
                    <?php
                    $device = trim(($invoice['device_brand'] ?? '') . ' ' . ($invoice['device_model'] ?? ''));
                    if ($device !== '' || !empty($invoice['serial_number'])):
                    ?>
                        <div style="margin-top: 6px; color: var(--muted); font-size: 9.5pt;">
                            <?php if ($device !== ''): ?>Zařízení: <?php echo $h($device); ?><br><?php endif; ?>
                            <?php if (!empty($invoice['serial_number'])): ?>S/N: <?php echo $h($invoice['serial_number']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    </table>

    <table class="dates">
        <tr>
            <td>
                <span class="lbl">Datum vystavení</span>
                <span class="val"><?php echo $h($fmt_date($invoice['date_issue'] ?? $invoice['created_at'])); ?></span>
            </td>
            <td>
                <span class="lbl">Datum splatnosti</span>
                <span class="val"><?php echo $h($fmt_date($invoice['date_due'] ?? null)); ?></span>
            </td>
            <td>
                <?php if ($is_vat_payer): ?>
                    <span class="lbl">DUZP (zdanitelné plnění)</span>
                    <span class="val"><?php echo $h($fmt_date($invoice['date_tax'] ?? $invoice['date_issue'] ?? null)); ?></span>
                <?php else: ?>
                    <span class="lbl">Datum uskutečnění / dodání</span>
                    <span class="val"><?php echo $h($fmt_date($invoice['date_tax'] ?? $invoice['date_issue'] ?? null)); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <span class="lbl">Forma úhrady</span>
                <span class="val"><?php echo $h($payment_method); ?></span>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 36px;" class="text-center">#</th>
                <th>Položka / popis plnění</th>
                <th class="text-right" style="width: 70px;">Množství</th>
                <th class="text-right" style="width: 90px;">Cena / mj.</th>
                <?php if ($is_vat_payer): ?>
                    <th class="text-right" style="width: 60px;">DPH %</th>
                    <th class="text-right" style="width: 90px;">Základ</th>
                    <th class="text-right" style="width: 80px;">DPH</th>
                <?php endif; ?>
                <th class="text-right" style="width: 100px;">Celkem</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $idx => $line): ?>
            <tr>
                <td class="text-center"><?php echo $idx + 1; ?></td>
                <td><?php echo $h($line['name']); ?></td>
                <td class="text-right">
                    <?php
                    $q = $line['qty'];
                    $q_fmt = (abs($q - round($q)) < 0.001)
                        ? (string)(int)round($q)
                        : number_format($q, 2, ',', "\u{00A0}");
                    echo $h($q_fmt . ' ' . $line['unit']);
                    ?>
                </td>
                <td class="text-right"><?php echo $h(number_format($line['unit_price'], 2, ',', "\u{00A0}")); ?></td>
                <?php if ($is_vat_payer): ?>
                    <td class="text-right"><?php echo $h(rtrim(rtrim(number_format($line['rate'], 2, ',', ''), '0'), ',')); ?></td>
                    <td class="text-right"><?php echo $h(number_format($line['base'], 2, ',', "\u{00A0}")); ?></td>
                    <td class="text-right"><?php echo $h(number_format($line['vat'], 2, ',', "\u{00A0}")); ?></td>
                <?php endif; ?>
                <td class="text-right"><strong><?php echo $h(number_format($line['gross'], 2, ',', "\u{00A0}")); ?></strong></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-wrap">
        <div class="vat-box">
            <?php if ($is_vat_payer && !empty($vat_summary)): ?>
                <h3>Rekapitulace DPH</h3>
                <table class="vat-table">
                    <thead>
                        <tr>
                            <th>Sazba</th>
                            <th class="text-right">Základ</th>
                            <th class="text-right">DPH</th>
                            <th class="text-right">Celkem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vat_summary as $row): ?>
                        <tr>
                            <td><?php echo $h(rtrim(rtrim(number_format($row['rate'], 2, ',', ''), '0'), ',') . ' %'); ?></td>
                            <td class="text-right"><?php echo $h(number_format($row['base'], 2, ',', "\u{00A0}")); ?></td>
                            <td class="text-right"><?php echo $h(number_format($row['vat'], 2, ',', "\u{00A0}")); ?></td>
                            <td class="text-right"><?php echo $h(number_format($row['base'] + $row['vat'], 2, ',', "\u{00A0}")); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th>Celkem</th>
                            <th class="text-right"><?php echo $h(number_format($base_total, 2, ',', "\u{00A0}")); ?></th>
                            <th class="text-right"><?php echo $h(number_format($vat_total, 2, ',', "\u{00A0}")); ?></th>
                            <th class="text-right"><?php echo $h(number_format($base_total + $vat_total, 2, ',', "\u{00A0}")); ?></th>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <h3>Daň z přidané hodnoty</h3>
                <p style="margin: 0; font-size: 10.5pt;">
                    <strong>Dodavatel není plátcem DPH.</strong><br>
                    Cena neobsahuje daň z přidané hodnoty (DPH).<br>
                    Tento doklad není daňovým dokladem ve smyslu zákona o DPH.
                </p>
            <?php endif; ?>
        </div>

        <div class="pay-box">
            <h3>Platební údaje</h3>
            <?php if ($bank_name !== ''): ?>
                <div><span class="k">Banka:</span> <?php echo $h($bank_name); ?></div>
            <?php endif; ?>
            <?php if ($bank_account !== ''): ?>
                <div><span class="k">Číslo účtu:</span> <strong><?php echo $h($bank_account); ?></strong></div>
            <?php endif; ?>
            <?php if ($iban !== ''): ?>
                <div><span class="k">IBAN:</span> <?php echo $h($iban); ?></div>
            <?php endif; ?>
            <?php if ($swift !== ''): ?>
                <div><span class="k">SWIFT/BIC:</span> <?php echo $h($swift); ?></div>
            <?php endif; ?>
            <?php if (!empty($invoice['variable_symbol'])): ?>
                <div><span class="k">VS:</span> <strong><?php echo $h($invoice['variable_symbol']); ?></strong></div>
            <?php endif; ?>
            <?php if ($qr_spd !== ''): ?>
                <div class="qr-wrap">
                    <img src="<?php echo $h(crmQrDataUri($qr_spd)); ?>"
                         alt="QR platba"
                         width="96"
                         height="96">
                    <div class="qr-caption">QR platba (SPD)</div>
                </div>
            <?php endif; ?>
        </div>

        <div class="grand">
            <div class="lbl">Celkem k úhradě</div>
            <div class="val"><?php echo $h($money($display_total)); ?></div>
            <?php if ($is_vat_payer): ?>
                <div class="sub">
                    Základ: <?php echo $h($money($base_total)); ?><br>
                    DPH: <?php echo $h($money($vat_total)); ?>
                </div>
            <?php else: ?>
                <div class="sub">Cena bez DPH (neplátce)</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="legal">
        <?php if (!$is_vat_payer): ?>
            <p><strong class="nonvat">Dodavatel není plátcem DPH. Ceny jsou konečné, bez DPH.</strong></p>
        <?php endif; ?>
        <?php if (!empty($invoice['notes'])): ?>
            <p><strong>Poznámka:</strong> <?php echo nl2br($h($invoice['notes'])); ?></p>
        <?php endif; ?>
        <p>
            Doklad slouží jako daňový / účetní doklad o poskytnutém plnění
            <?php if (!$is_vat_payer): ?>(mimo režim DPH)<?php endif; ?>.
            Převzetím zboží nebo služby odběratel stvrzuje souhlas s uvedenými údaji a platebními podmínkami.
        </p>
        <?php if (($invoice['payment_method'] ?? '') === 'bank_transfer'): ?>
            <p>Prosíme o úhradu pod variabilním symbolem uvedeným na tomto dokladu.</p>
        <?php endif; ?>
    </div>

    <div class="sign-row">
        <div class="sign-box">
            <div><strong>Vystavil:</strong></div>
            <div class="sign-line">Podpis dodavatele</div>
        </div>
        <div class="sign-box">
            <div><strong>Odběratel</strong></div>
            <div class="sign-line">Podpis / razítko odběratele</div>
        </div>
    </div>

    <div class="page-foot">
        <span><?php echo $h($company_name); ?><?php echo $company_ico !== '' ? ' · IČO ' . $h($company_ico) : ''; ?></span>
        <span><?php echo $h($doc_title); ?> <?php echo $h($invoice['invoice_number']); ?></span>
    </div>
</div>

<script nonce="<?php echo e(crmCspNonce()); ?>" src="assets/js/print.js"></script>
</body>
</html>
