<?php
/**
 * Technician payroll statement — 80 mm thermal receipt.
 * reports.php → print per employee for the selected period.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/reports_stats.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(__('unauthorized'));
}

$isAdmin = hasPermission('admin_access');
$isTech = ($_SESSION['role'] ?? '') === 'technician';
$techId = (int)($_GET['tech_id'] ?? 0);

if ($techId <= 0) {
    http_response_code(400);
    die(__('select_tech_prompt'));
}

// Technicians may print only their own statement; admins may print any.
if (!$isAdmin) {
    if (!$isTech || (int)($_SESSION['tech_id'] ?? 0) !== $techId) {
        http_response_code(403);
        die(__('unauthorized'));
    }
}

$defaultStartDate = date('Y-m-d', strtotime('monday this week'));
$defaultEndDate = date('Y-m-d', strtotime('sunday this week'));
$startDate = (string)($_GET['start_date'] ?? $defaultStartDate);
$endDate = (string)($_GET['end_date'] ?? $defaultEndDate);
$startObj = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
$endObj = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
if (
    $startObj === false ||
    $endObj === false ||
    $startObj->format('Y-m-d') !== $startDate ||
    $endObj->format('Y-m-d') !== $endDate ||
    $endObj < $startObj ||
    $startObj->diff($endObj)->days > 366
) {
    $startDate = $defaultStartDate;
    $endDate = $defaultEndDate;
}

$payroll = crmGetTechnicianPayroll($pdo, $techId, $startDate, $endDate);
if ($payroll['technician'] === null) {
    http_response_code(404);
    die(__('print_not_found'));
}

require_once __DIR__ . '/includes/partials/thermal_receipt_css.php';
$supplier = crmThermalSupplierProfile();

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

$techName = $payroll['technician']['name'];
$rate = $payroll['rate'];
$orders = $payroll['orders'];
$totals = $payroll['totals'];
$printedAt = date('d.m.Y H:i');
$periodLabel = date('d.m.Y', strtotime($startDate)) . ' – ' . date('d.m.Y', strtotime($endDate));

$crmScriptNonce = function_exists('crmCspNonce') ? (string)crmCspNonce() : '';
$backUrl = 'reports.php?tab=individual_stats&tech_id=' . $techId
    . '&start_date=' . rawurlencode($startDate)
    . '&end_date=' . rawurlencode($endDate);
?>
<!DOCTYPE html>
<html lang="<?php echo $h($_SESSION['lang'] ?? 'ru'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $h(__('payroll_statement')); ?> — <?php echo $h($techName); ?></title>
    <?php crmThermalReceiptCss(); ?>
    <style>
        .payroll-line { margin-bottom: 6px; }
        .payroll-line .device {
            font-size: 10px;
            font-weight: 400;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 68mm;
        }
        .sig-block {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px dashed #000;
            font-size: 11px;
        }
        .sig-line {
            margin-top: 14px;
            border-top: 1px solid #000;
            padding-top: 2px;
        }
        .sig-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 10px;
        }
        .sig-row > div { width: 48%; }
    </style>
</head>
<body data-auto-print="<?php echo empty($_GET['embed']) ? '1' : '0'; ?>">

<div class="no-print">
    <button type="button" class="btn-print" data-print-action="print"><?php echo __('print_btn'); ?></button>
    <a class="btn-back" href="<?php echo $h($backUrl); ?>"><?php echo __('back'); ?></a>
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
    </div>

    <div class="doc-title"><?php echo $h(__('payroll_statement')); ?></div>
    <div class="doc-number"><?php echo $h($periodLabel); ?></div>

    <div class="section">
        <div class="row">
            <span class="label"><?php echo $h(__('technician')); ?>:</span>
            <span><strong><?php echo $h($techName); ?></strong></span>
        </div>
        <div class="row">
            <span class="label"><?php echo $h(__('engineer_rate_label')); ?>:</span>
            <span><?php echo $h(rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',') . ' %'); ?></span>
        </div>
        <div class="row">
            <span class="label"><?php echo $h(__('repaired_count')); ?>:</span>
            <span><?php echo (int)$totals['count']; ?></span>
        </div>
        <div class="muted"><?php echo $h(__('printed_at')); ?>: <?php echo $h($printedAt); ?></div>
    </div>

    <div class="section">
        <div class="label" style="margin-bottom:4px;"><?php echo $h(__('completed_works_list')); ?></div>
        <?php if (empty($orders)): ?>
            <div class="muted"><?php echo $h(__('not_found')); ?></div>
        <?php else: ?>
            <?php foreach ($orders as $line): ?>
                <?php
                $dateStr = !empty($line['finance_date'])
                    ? date('d.m.Y', strtotime((string)$line['finance_date']))
                    : '—';
                ?>
                <div class="item payroll-line">
                    <span class="item-name">#<?php echo (int)$line['id']; ?> · <?php echo $h($dateStr); ?></span>
                    <?php if ($line['device'] !== ''): ?>
                        <span class="device"><?php echo $h($line['device']); ?></span>
                    <?php endif; ?>
                    <div class="item-details">
                        <span><?php echo $h(__('amount')); ?></span>
                        <span><?php echo $h($money($line['customer_total'])); ?></span>
                    </div>
                    <?php if ($line['parts_cost'] > 0): ?>
                    <div class="item-details">
                        <span><?php echo $h(__('parts_cost')); ?></span>
                        <span>-<?php echo $h($money($line['parts_cost'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($line['extra_expenses'] > 0): ?>
                    <div class="item-details">
                        <span><?php echo $h(__('extra_expenses')); ?></span>
                        <span>-<?php echo $h($money($line['extra_expenses'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="item-details">
                        <span class="label"><?php echo $h(__('earnings_col')); ?></span>
                        <span><strong><?php echo $h($money($line['earnings'])); ?></strong></span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="total-section">
        <div class="total-row">
            <span><?php echo $h(__('earned')); ?></span>
            <span><?php echo $h($money($totals['earnings'])); ?></span>
        </div>
    </div>

    <div class="sig-block">
        <div class="sig-row">
            <div>
                <div class="label"><?php echo $h(__('payroll_signature_admin')); ?></div>
                <div class="sig-line">&nbsp;</div>
            </div>
            <div>
                <div class="label"><?php echo $h(__('payroll_signature_employee')); ?></div>
                <div class="sig-line">&nbsp;</div>
            </div>
        </div>
    </div>

    <div class="footer">
        <div class="muted"><?php echo $h(__('payroll_footer_note')); ?></div>
    </div>
</div>

<script<?php echo $crmScriptNonce !== '' ? ' nonce="' . e($crmScriptNonce) . '"' : ''; ?> src="assets/js/print.js"></script>
</body>
</html>
