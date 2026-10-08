<?php
/**
 * Order thermal receipt — Czech doklad for neplátce DPH (same layout as invoice thermal).
 * Menu: Заказы → Чек (Термопринтер)
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    die(__('unauthorized'));
}

$id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
if ($id <= 0) {
    die(__('order_id_missing'));
}
if (!currentUserCanViewOrder($id)) {
    http_response_code(403);
    die(__('unauthorized'));
}

$stmt = $pdo->prepare(
    "SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic
     FROM orders o
     JOIN customers c ON o.customer_id = c.id
     WHERE o.id = ?"
);
$stmt->execute([$id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die(__('print_not_found'));
}

// PIN is not printed on the client payment receipt (security + not required by law).
try {
    $stmt = $pdo->prepare(
        "SELECT oi.*, COALESCE(oi.part_name, i.part_name) AS part_name
         FROM order_items oi
         LEFT JOIN inventory i ON oi.inventory_id = i.id
         WHERE oi.order_id = ?
         ORDER BY oi.id ASC"
    );
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $pdo->prepare(
        "SELECT oi.*, i.part_name FROM order_items oi
         JOIN inventory i ON oi.inventory_id = i.id
         WHERE oi.order_id = ?"
    );
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/includes/partials/thermal_receipt_css.php';
$supplier = crmThermalSupplierProfile();
$is_vat_payer = $supplier['is_vat'];

$currency = get_setting('currency', 'Kč');
if (in_array(mb_strtolower((string)$currency), ['kc', 'czk', 'kč'], true)) {
    $currency = 'Kč';
}

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$money = static function ($amount) use ($currency): string {
    return number_format((float)$amount, 2, ',', ' ') . ' ' . $currency;
};

$cust_name = trim((string)(
    ($order['company'] ?? '') !== ''
        ? $order['company']
        : trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''))
));
$device = trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));

// Customer total: final_cost is authoritative; do not double-count parts into it.
$order_total = (float)($order['final_cost'] ?? 0);
if ($order_total <= 0) {
    $order_total = (float)($order['estimated_cost'] ?? 0);
}

$parts_sum = 0.0;
$lines = [];
foreach ($items as $item) {
    $qty = (float)($item['quantity'] ?? 1);
    $price = (float)($item['price'] ?? 0);
    $line = $qty * $price;
    $parts_sum += $line;
    $lines[] = [
        'name' => (string)($item['part_name'] ?? 'Díl'),
        'qty' => $qty,
        'unit' => (string)($item['unit'] ?? 'ks'),
        'price' => $price,
        'line' => $line,
    ];
}

// Labor / service remainder so line items sum to order total (no double count).
$labor = round($order_total - $parts_sum, 2);
if (abs($labor) >= 0.01) {
    array_unshift($lines, [
        'name' => 'Oprava / práce' . ($device !== '' ? ': ' . $device : ''),
        'qty' => 1.0,
        'unit' => 'ks',
        'price' => $labor,
        'line' => $labor,
    ]);
} elseif (empty($lines)) {
    $lines[] = [
        'name' => 'Oprava / servisní služba' . ($device !== '' ? ': ' . $device : ''),
        'qty' => 1.0,
        'unit' => 'ks',
        'price' => $order_total,
        'line' => $order_total,
    ];
}

// Document title — same family as invoice thermal for neplátce DPH.
if ($is_vat_payer) {
    $doc_title = (abs($order_total) <= 10000.0)
        ? 'ZJEDNODUŠENÝ DAŇOVÝ DOKLAD'
        : 'FAKTURA - DAŇOVÝ DOKLAD';
} else {
    $doc_title = 'DOKLAD O ZAPLACENÍ';
}

$doc_number = 'Z' . (int)$order['id'];
$issue_date = !empty($order['updated_at'])
    ? date('d.m.Y H:i', strtotime($order['updated_at']))
    : date('d.m.Y H:i', strtotime($order['created_at'] ?? 'now'));

$crmScriptNonce = function_exists('crmCspNonce') ? (string)crmCspNonce() : '';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?php echo $h($doc_title); ?> <?php echo $h($doc_number); ?></title>
    <?php crmThermalReceiptCss(); ?>
</head>
<body data-auto-print="<?php echo empty($_GET['embed']) ? '1' : '0'; ?>">

<div class="no-print">
    <button type="button" class="btn-print" data-print-action="print"><?php echo __('print_btn'); ?></button>
    <a class="btn-back" href="orders.php"><?php echo __('back'); ?></a>
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
    <div class="doc-number">č. <?php echo $h($doc_number); ?></div>

    <div class="section">
        <div class="row"><span class="label">Datum:</span><span><?php echo $h($issue_date); ?></span></div>
        <div class="row"><span class="label">Zakázka:</span><span>#<?php echo (int)$order['id']; ?></span></div>
        <?php if ($device !== ''): ?>
            <div class="muted"><?php echo $h($device); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['serial_number'])): ?>
            <div class="muted">S/N: <?php echo $h($order['serial_number']); ?></div>
        <?php endif; ?>
        <div class="row"><span class="label">Forma úhrady:</span><span>Hotově / Kartou</span></div>
    </div>

    <div class="section">
        <div class="label">Odběratel</div>
        <div><strong><?php echo $h($cust_name !== '' ? $cust_name : '—'); ?></strong></div>
        <?php if (!empty($order['phone'])): ?>
            <div class="muted">Tel: <?php echo $h($order['phone']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['address'])): ?>
            <div class="muted"><?php echo $h($order['address']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['ico'])): ?>
            <div class="muted">IČO: <?php echo $h($order['ico']); ?></div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="label" style="margin-bottom: 4px;">Položky</div>
        <?php foreach ($lines as $line): ?>
            <div class="item">
                <span class="item-name"><?php echo $h($line['name']); ?></span>
                <div class="item-details">
                    <span>
                        <?php
                        $q = $line['qty'];
                        $q_fmt = (abs($q - round($q)) < 0.001)
                            ? (string)(int)round($q)
                            : number_format($q, 2, ',', ' ');
                        echo $h($q_fmt . ' ' . $line['unit']);
                        ?>
                        × <?php echo number_format($line['price'], 2, ',', ' '); ?>
                    </span>
                    <span><strong><?php echo $h($money($line['line'])); ?></strong></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="total-section">
        <div class="total-row">
            <span>CELKEM:</span>
            <span><?php echo $h($money($order_total)); ?></span>
        </div>
    </div>

    <?php if (!$is_vat_payer): ?>
        <div class="legal-note">
            Dodavatel není plátcem DPH.<br>
            Cena neobsahuje daň z přidané hodnoty.
        </div>
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
