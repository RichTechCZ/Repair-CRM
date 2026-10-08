<?php
/**
 * Invoice thermal receipt — Czech layout (same design as print_thermal.php).
 * Menu: Бухгалтерия → thermal / view_order invoice thermal.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

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
    "SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic,
            o.device_brand, o.device_model, o.serial_number
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

require_once __DIR__ . '/includes/partials/thermal_receipt_css.php';
$supplier = crmThermalSupplierProfile();

// Prefer snapshot on invoice; fall back to accounting settings.
$is_vat_payer = !empty($invoice['is_vat_payer']) || $supplier['is_vat'];
$is_credit = (($invoice['invoice_type'] ?? '') === 'credit_note');

$currency = $invoice['currency'] ?: 'Kč';
if (in_array(mb_strtolower((string)$currency), ['kc', 'czk', 'kč'], true)) {
    $currency = 'Kč';
}

$payment_methods = [
    'bank_transfer' => 'Bankovní převod',
    'cash' => 'Hotovost',
    'card' => 'Kartou',
    'cod' => 'Dobírka',
];
$payment_method = $payment_methods[$invoice['payment_method']] ?? (string)$invoice['payment_method'];

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$money = static function ($amount) use ($currency): string {
    return number_format((float)$amount, 2, ',', ' ') . ' ' . $currency;
};

$cust_name = trim((string)(
    $invoice['cust_name_override']
        ?: ($invoice['company'] ?: trim(($invoice['first_name'] ?? '') . ' ' . ($invoice['last_name'] ?? '')))
));
$cust_address = trim((string)($invoice['cust_address_override'] ?: ($invoice['address'] ?? '')));
$cust_ico = trim((string)($invoice['cust_ico_override'] ?: ($invoice['ico'] ?? '')));
$cust_dic = trim((string)($invoice['cust_dic_override'] ?: ($invoice['dic'] ?? '')));

if ($is_credit) {
    $doc_title = $is_vat_payer ? 'OPRAVNÝ DAŇOVÝ DOKLAD' : 'DOBROPIS / OPRAVNÝ DOKLAD';
} elseif ($is_vat_payer) {
    $total_abs = abs((float)$invoice['total_amount']);
    $doc_title = ($total_abs <= 10000.0) ? 'ZJEDNODUŠENÝ DAŇOVÝ DOKLAD' : 'FAKTURA - DAŇOVÝ DOKLAD';
} else {
    // Same family as order thermal for neplátce DPH.
    $doc_title = in_array($invoice['payment_method'] ?? '', ['cash', 'card'], true)
        ? 'DOKLAD O ZAPLACENÍ'
        : 'FAKTURA';
}

$issue_date = !empty($invoice['date_issue'])
    ? date('d.m.Y', strtotime($invoice['date_issue']))
    : date('d.m.Y', strtotime($invoice['created_at']));

$crmScriptNonce = function_exists('crmCspNonce') ? (string)crmCspNonce() : '';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?php echo $h($doc_title); ?> <?php echo $h($invoice['invoice_number']); ?></title>
    <?php crmThermalReceiptCss(); ?>
</head>
<body data-auto-print="<?php echo empty($_GET['embed']) ? '1' : '0'; ?>">

<div class="no-print">
    <button type="button" class="btn-print" data-print-action="print"><?php echo __('print_btn'); ?></button>
    <a class="btn-back" href="accounting.php"><?php echo __('back'); ?></a>
</div>

<div class="receipt">
    <div class="header">
        <div class="company-name"><?php echo $h($supplier['name']); ?></div>
        <?php if ($supplier['address'] !== ''): ?>
            <div class="muted"><?php echo nl2br($h($supplier['address'])); ?></div>
        <?php endif; ?>
        <?php if ($supplier['ico'] !== ''): ?>
            <div class="muted">IČO: <?php echo $h($supplier['ico']); ?></div>
        <?php endif; ?>
        <?php if ($is_vat_payer && $supplier['dic'] !== ''): ?>
            <div class="muted">DIČ: <?php echo $h($supplier['dic']); ?></div>
        <?php endif; ?>
        <?php if (!$is_vat_payer): ?>
            <div class="badge-nonvat">Neplátce DPH</div>
        <?php endif; ?>
    </div>

    <div class="doc-title"><?php echo $h($doc_title); ?></div>
    <div class="doc-number">č. <?php echo $h($invoice['invoice_number']); ?></div>

    <div class="section">
        <div class="row"><span class="label">Datum vystavení:</span><span><?php echo $h($issue_date); ?></span></div>
        <?php if (!empty($invoice['variable_symbol'])): ?>
            <div class="row"><span class="label">Var. symbol:</span><span><?php echo $h($invoice['variable_symbol']); ?></span></div>
        <?php endif; ?>
        <div class="row"><span class="label">Forma úhrady:</span><span><?php echo $h($payment_method); ?></span></div>
        <?php if (!empty($invoice['order_id'])): ?>
            <div class="row"><span class="label">Zakázka:</span><span>#<?php echo (int)$invoice['order_id']; ?></span></div>
            <?php
            $device = trim(($invoice['device_brand'] ?? '') . ' ' . ($invoice['device_model'] ?? ''));
            if ($device !== ''):
            ?>
                <div class="muted"><?php echo $h($device); ?></div>
            <?php endif; ?>
            <?php if (!empty($invoice['serial_number'])): ?>
                <div class="muted">S/N: <?php echo $h($invoice['serial_number']); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="label">Odběratel</div>
        <div><strong><?php echo $h($cust_name !== '' ? $cust_name : '—'); ?></strong></div>
        <?php if ($cust_address !== ''): ?>
            <div class="muted"><?php echo nl2br($h($cust_address)); ?></div>
        <?php endif; ?>
        <?php if ($cust_ico !== ''): ?>
            <div class="muted">IČO: <?php echo $h($cust_ico); ?></div>
        <?php endif; ?>
        <?php if ($is_vat_payer && $cust_dic !== ''): ?>
            <div class="muted">DIČ: <?php echo $h($cust_dic); ?></div>
        <?php endif; ?>
        <?php if (!empty($invoice['phone'])): ?>
            <div class="muted">Tel: <?php echo $h($invoice['phone']); ?></div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="label" style="margin-bottom: 4px;">Položky</div>
        <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
                <?php
                $qty = (float)$item['quantity'];
                $price = (float)$item['price'];
                $line = $qty * $price;
                $vat_label = ($is_vat_payer && isset($item['vat_rate']))
                    ? ' (DPH ' . rtrim(rtrim(number_format((float)$item['vat_rate'], 2, ',', ' '), '0'), ',') . ' %)'
                    : '';
                ?>
                <div class="item">
                    <span class="item-name"><?php echo $h($item['item_name']); ?><?php echo $h($vat_label); ?></span>
                    <div class="item-details">
                        <span><?php echo $h(rtrim(rtrim(number_format($qty, 2, ',', ' '), '0'), ',') . ' ' . ($item['unit'] ?: 'ks')); ?>
                            × <?php echo number_format($price, 2, ',', ' '); ?></span>
                        <span><strong><?php echo $h($money($line)); ?></strong></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="item">
                <span class="item-name"><?php echo $h($invoice['notes'] ?: 'Oprava / služba'); ?></span>
                <div class="item-details">
                    <span>1 ks</span>
                    <span><strong><?php echo $h($money($invoice['total_amount'])); ?></strong></span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="total-section">
        <div class="total-row">
            <span>CELKEM:</span>
            <span><?php echo $h($money($invoice['total_amount'])); ?></span>
        </div>
    </div>

    <?php if (!$is_vat_payer): ?>
        <div class="legal-note">
            Dodavatel není plátcem DPH.<br>
            Cena neobsahuje daň z přidané hodnoty.
        </div>
    <?php elseif (!empty($invoice['vat_amount'])): ?>
        <div class="section" style="margin-top: 6px; border-bottom: none;">
            <div class="row"><span class="label">DPH celkem:</span><span><?php echo $h($money($invoice['vat_amount'])); ?></span></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($invoice['variable_symbol'])): ?>
        <div class="barcode">*<?php echo $h($invoice['variable_symbol']); ?>*</div>
    <?php endif; ?>

    <div class="footer">
        <div>Děkujeme za Vaši důvěru!</div>
        <div class="footer-phone"><?php echo $h(CRM_THERMAL_CLIENT_PHONE); ?></div>
        <div class="muted" style="margin-top: 2px;"><?php echo $h($supplier['name']); ?></div>
        <div class="muted">www.servis.expert</div>
    </div>
</div>

<script<?php echo $crmScriptNonce !== '' ? ' nonce="' . e($crmScriptNonce) . '"' : ''; ?> src="assets/js/print.js"></script>
</body>
</html>
