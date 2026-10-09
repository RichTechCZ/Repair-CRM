<?php
/**
 * Client reception act for 80 mm thermal printers.
 * Předávací protokol — only the customer copy.
 * Workshop uses print_workshop.php (work order / разнарядка мастера).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/qr_code.php';

if (!isset($_SESSION['user_id'])) {
    die(__('unauthorized'));
}

$id = (int)($_GET['id'] ?? $_GET['order_id'] ?? 0);
if ($id <= 0) {
    die(__('order_id_missing'));
}

$target_lang = strtolower(trim((string)($_GET['lang'] ?? 'cs')));
if (!in_array($target_lang, ['cs', 'ru'], true)) {
    $target_lang = 'cs';
}

if (!currentUserCanViewOrder($id)) {
    http_response_code(403);
    die(__('unauthorized'));
}

function _l(string $key): string
{
    global $target_lang;
    return __($key, $target_lang);
}

$stmt = $pdo->prepare(
    "SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico,
            t.name AS tech_name
     FROM orders o
     JOIN customers c ON o.customer_id = c.id
     LEFT JOIN technicians t ON o.technician_id = t.id
     WHERE o.id = ?"
);
$stmt->execute([$id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die(__('order_not_found'));
}

if (function_exists('crmDecryptDevicePinInRow')) {
    crmDecryptDevicePinInRow($order);
}

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

/** Contact phone on the customer slip footer. */
const RECEPTION_CLIENT_PHONE = '+420 774 008 600';

$company_name = trim((string)get_setting('company_name', get_setting('acc_company_name', 'Repair CRM')));
$company_address = trim((string)get_setting('company_address', get_setting('acc_address', '')));
$company_ico = trim((string)get_setting('acc_ico', ''));

$client_name = trim((string)(
    ($order['company'] ?? '') !== ''
        ? $order['company']
        : trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''))
));
$device = trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));
$is_warranty = (($order['order_type'] ?? '') === 'Warranty');
$order_type_label = $is_warranty ? _l('warranty') : _l('non_warranty');

$created_label = !empty($order['created_at'])
    ? date('d.m.Y H:i', strtotime($order['created_at']))
    : '—';

$publicToken = crmEnsureOrderPublicStatusToken($pdo, (int)$order['id']);
if ($publicToken === '' && !empty($order['public_status_token'])) {
    $publicToken = crmNormalizePublicStatusToken((string)$order['public_status_token']);
}
$status_url = $publicToken !== ''
    ? crmOrderPublicStatusUrl($publicToken)
    : crmPublicBaseUrl() . '/status.php';
// Printed without the scheme to stay short on 80 mm paper.
$status_url_display = (string)preg_replace('#^https?://#', '', $status_url);
$pin = trim((string)($order['pin_code'] ?? ''));
$has_pin = $pin !== '';

// Client slip: do not print full PIN — only that it was recorded.
$pin_display = '';
if ($has_pin) {
    $len = mb_strlen($pin);
    $pin_display = $len <= 2
        ? str_repeat('•', max(1, $len))
        : (mb_substr($pin, 0, 1) . str_repeat('•', max(2, $len - 2)) . mb_substr($pin, -1));
}

$terms_lines = preg_split("/\r\n|\n|\r/", _l('reception_terms_body')) ?: [];
$crmScriptNonce = function_exists('crmCspNonce') ? (string)crmCspNonce() : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $h($target_lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $h(_l('reception_act')); ?> #<?php echo (int)$order['id']; ?></title>
    <style>
        :root { --ink: #000; }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, "DejaVu Sans", sans-serif;
            font-size: 12px;
            line-height: 1.28;
            width: 72mm;
            margin: 0 auto;
            padding: 0;
            color: var(--ink);
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .sheet {
            width: 72mm;
            padding: 2mm 2.5mm 4mm;
        }
        .center { text-align: center; }
        .bold { font-weight: 800; }
        .muted { font-size: 10px; color: #222; }
        .tiny { font-size: 9.5px; line-height: 1.25; }
        .rule { border: 0; border-top: 1.5px dashed #000; margin: 7px 0; }
        .rule-solid { border: 0; border-top: 2px solid #000; margin: 8px 0; }
        .brand {
            font-size: 14px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 2px;
        }
        .doc-title {
            font-size: 13px;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin: 4px 0 2px;
        }
        .copy-badge {
            display: inline-block;
            border: 1.5px solid #000;
            padding: 2px 6px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin: 3px 0 2px;
        }
        .order-no {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 0.02em;
            margin: 4px 0 1px;
        }
        .chip {
            display: inline-block;
            border: 1px solid #000;
            padding: 1px 5px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            margin-top: 3px;
        }
        .chip-warranty { background: #000; color: #fff; }
        .section-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin: 0 0 3px;
        }
        .block { margin-bottom: 2px; }
        .name { font-size: 13px; font-weight: 800; }
        .device-name { font-size: 13px; font-weight: 900; margin-bottom: 2px; }
        .row {
            display: flex;
            justify-content: space-between;
            gap: 6px;
            margin: 2px 0;
        }
        .row .k { font-weight: 700; flex: 0 0 auto; }
        .row .v { text-align: right; font-weight: 700; word-break: break-word; }
        .box {
            border: 1.5px solid #000;
            padding: 5px 6px;
            margin: 4px 0;
        }
        .box p {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .est {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
            margin: 2px 0;
        }
        .est-val { font-size: 16px; font-weight: 900; }
        .qr { margin: 8px 0 4px; }
        .qr img {
            width: 34mm;
            height: 34mm;
            image-rendering: pixelated;
        }
        .terms-title {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }
        .terms-list {
            margin: 0;
            padding-left: 14px;
            font-size: 9.5px;
            line-height: 1.3;
        }
        .terms-list li { margin-bottom: 2px; }
        .signs { margin-top: 10px; }
        .sign { margin-top: 14px; }
        .sign-line {
            border-top: 1px solid #000;
            margin-top: 22px;
            padding-top: 3px;
            font-size: 9.5px;
            text-align: center;
            font-weight: 700;
        }
        .foot {
            margin-top: 8px;
            font-size: 10px;
            font-weight: 700;
        }
        .foot-phone {
            margin-top: 6px;
            font-size: 14px;
            font-weight: 900;
            letter-spacing: 0.02em;
        }
        .toolbar {
            text-align: center;
            margin: 16px 0 28px;
        }
        .toolbar button {
            padding: 10px 18px;
            font-size: 14px;
            margin: 0 4px;
            cursor: pointer;
            border: 1px solid #333;
            background: #111;
            color: #fff;
            border-radius: 4px;
        }
        .toolbar button.secondary {
            background: #fff;
            color: #111;
        }
        @media print {
            @page { margin: 0; size: 80mm auto; }
            body { width: 72mm; background: none; }
            .no-print { display: none !important; }
            .sheet { padding: 1mm 2mm 3mm; }
        }
    </style>
</head>
<body data-auto-print="<?php echo empty($_GET['embed']) ? '1' : '0'; ?>">

<div class="sheet">
    <div class="center">
        <div class="brand"><?php echo $h($company_name); ?></div>
        <?php if ($company_address !== ''): ?>
            <div class="muted"><?php echo nl2br($h($company_address)); ?></div>
        <?php endif; ?>
        <?php if ($company_ico !== ''): ?>
            <div class="muted">IČO: <?php echo $h($company_ico); ?></div>
        <?php endif; ?>

        <hr class="rule-solid">

        <div class="doc-title"><?php echo $h(_l('reception_doc_title')); ?></div>
        <div class="copy-badge"><?php echo $h(_l('reception_copy_client')); ?></div>
        <div class="order-no"><?php echo $h(_l('reception_order_label')); ?> #<?php echo (int)$order['id']; ?></div>
        <div class="muted"><?php echo $h(_l('reception_accepted_at')); ?>: <span class="bold"><?php echo $h($created_label); ?></span></div>
        <div class="chip <?php echo $is_warranty ? 'chip-warranty' : ''; ?>"><?php echo $h($order_type_label); ?></div>
    </div>

    <hr class="rule">

    <div class="section-label"><?php echo $h(_l('client')); ?></div>
    <div class="block">
        <div class="name"><?php echo $h($client_name !== '' ? $client_name : '—'); ?></div>
        <?php if (!empty($order['phone'])): ?>
            <div><?php echo $h(_l('phone')); ?>: <span class="bold"><?php echo $h($order['phone']); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['address'])): ?>
            <div class="muted"><?php echo $h($order['address']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['ico'])): ?>
            <div class="muted">IČO: <?php echo $h($order['ico']); ?></div>
        <?php endif; ?>
    </div>

    <hr class="rule">

    <div class="section-label"><?php echo $h(_l('reception_device')); ?></div>
    <div class="device-name"><?php echo $h($device !== '' ? $device : '—'); ?></div>
    <?php if (!empty($order['device_type'])): ?>
        <div class="muted"><?php echo $h($order['device_type']); ?></div>
    <?php endif; ?>
    <div class="row">
        <span class="k"><?php echo $h(_l('reception_sn')); ?></span>
        <span class="v"><?php echo $h($order['serial_number'] ?: '—'); ?></span>
    </div>
    <?php if (!empty($order['serial_number_2'])): ?>
    <div class="row">
        <span class="k"><?php echo $h(_l('reception_sn2')); ?></span>
        <span class="v"><?php echo $h($order['serial_number_2']); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($has_pin): ?>
    <div class="row">
        <span class="k"><?php echo $h(_l('pin')); ?></span>
        <span class="v"><?php echo $h($pin_display); ?> ✓</span>
    </div>
    <?php endif; ?>

    <hr class="rule">

    <div class="section-label"><?php echo $h(_l('appearance')); ?></div>
    <div class="box">
        <p><?php echo $h(trim((string)($order['appearance'] ?? '')) !== '' ? $order['appearance'] : '—'); ?></p>
    </div>

    <div class="section-label"><?php echo $h(_l('problem')); ?></div>
    <div class="box">
        <p><?php echo $h(trim((string)($order['problem_description'] ?? '')) !== '' ? $order['problem_description'] : '—'); ?></p>
    </div>

    <hr class="rule">

    <div class="est">
        <span class="bold"><?php echo $h(mb_strtoupper(_l('cost_est'))); ?></span>
        <span class="est-val">
            <?php
            if ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
                echo $h(formatMoney($order['estimated_cost']));
            } else {
                echo '—';
            }
            ?>
        </span>
    </div>
    <div class="tiny"><?php echo $h(_l('reception_est_note')); ?></div>

    <hr class="rule-solid">

    <div class="center qr">
        <img
            src="<?php echo e(crmQrDataUri($status_url)); ?>"
            alt="QR status"
            width="128"
            height="128">
        <div class="tiny bold"><?php echo $h(_l('reception_status_qr')); ?></div>
        <div class="tiny"><?php echo $h($status_url_display); ?></div>
    </div>

    <hr class="rule">

    <div class="terms-title"><?php echo $h(_l('reception_terms_title')); ?></div>
    <ol class="terms-list">
        <?php foreach ($terms_lines as $line): ?>
            <?php
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^\d+\.\s*/', '', $line) ?? $line;
            ?>
            <li><?php echo $h($line); ?></li>
        <?php endforeach; ?>
    </ol>

    <div class="signs">
        <div class="sign">
            <div class="sign-line"><?php echo $h(_l('reception_sign_client')); ?></div>
        </div>
        <div class="sign">
            <div class="sign-line"><?php echo $h(_l('reception_sign_service')); ?></div>
        </div>
    </div>

    <div class="center foot">
        <div><?php echo $h(_l('reception_thanks')); ?></div>
        <div class="muted" style="margin-top: 3px;">
            <?php echo $h(_l('reception_act')); ?> #<?php echo (int)$order['id']; ?>
        </div>
        <div class="foot-phone"><?php echo $h(RECEPTION_CLIENT_PHONE); ?></div>
        <div class="bold" style="margin-top: 2px;">www.servis.expert</div>
    </div>
</div>

<div class="no-print toolbar">
    <button type="button" data-print-action="print"><?php echo $h(_l('print_btn')); ?></button>
    <button type="button" class="secondary" data-print-action="close"><?php echo $h(_l('close')); ?></button>
</div>

<script<?php echo $crmScriptNonce !== '' ? ' nonce="' . e($crmScriptNonce) . '"' : ''; ?> src="assets/js/print.js"></script>
</body>
</html>
