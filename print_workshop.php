<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("ID zakázky není zadáno");

$id = $_GET['id'] ?? $_GET['order_id'];
$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       LEFT JOIN technicians t ON o.technician_id = t.id
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Zakázka nenalezena");

// Fetch parts linked to this order
$stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$order_items = $stmt->fetchAll();

$target_lang = 'cs';
function _l($key) { return __($key, 'cs'); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Pracovní příkaz #<?php echo $order['id']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; color: #333; margin: 0; padding: 20px; }
        .work-order { max-width: 800px; margin: auto; border: 2px solid #000; padding: 20px; }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 24px; }
        .section { margin-bottom: 20px; }
        .section-title { font-weight: bold; text-transform: uppercase; background: #eee; padding: 5px 10px; border: 1px solid #000; margin-bottom: 10px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .label { font-weight: bold; color: #666; font-size: 12px; }
        .value { font-size: 16px; font-weight: bold; }
        .problem-box { border: 1px solid #000; padding: 15px; background: #fdfdfd; min-height: 100px; font-size: 16px; }
        .footer { margin-top: 30px; font-size: 12px; border-top: 1px solid #ccc; padding-top: 10px; }
        .qr-section { text-align: right; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .work-order { border-width: 1px; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin-bottom: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #000; color: white; border: none; font-weight: bold;">Tisk pracovního příkazu</button>
</div>

<div class="work-order">
    <div class="header">
        <div>
            <h1><?php echo mb_strtoupper(_l('work_order')); ?> č. <?php echo $order['id']; ?></h1>
            <div><?php echo _l('created'); ?>: <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></div>
        </div>
        <div class="qr-section">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?php echo urlencode($_SERVER['HTTP_HOST'].'/view_order.php?id='.$order['id']); ?>">
        </div>
    </div>

    <div class="section">
        <div class="section-title"><?php echo _l('device_brand'); ?> / <?php echo _l('device_model'); ?></div>
        <div class="grid">
            <div>
                <div class="label"><?php echo mb_strtoupper(_l('device_model')); ?>:</div>
                <div class="value"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
            </div>
            <div>
                <div class="label"><?php echo mb_strtoupper(_l('device_type')); ?> / <?php echo mb_strtoupper(_l('status')); ?>:</div>
                <div class="value"><?php echo $order['device_type']; ?> / <?php echo $order['order_type'] == 'Warranty' ? _l('warranty') : _l('non_warranty'); ?></div>
            </div>
            <div>
                <div class="label">S/N:</div>
                <div class="value"><?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?></div>
            </div>
            <div>
                <div class="label"><?php echo _l('serial_2'); ?>:</div>
                <div class="value"><?php echo htmlspecialchars($order['serial_number_2'] ?: '---'); ?></div>
            </div>
            <div>
                <div class="label"><?php echo mb_strtoupper(_l('pin')); ?>:</div>
                <div class="value" style="font-family: monospace; letter-spacing: 2px;"><?php echo htmlspecialchars($order['pin_code'] ?: _l('not_found')); ?></div>
            </div>
            <div>
                <div class="label"><?php echo mb_strtoupper(_l('priority')); ?>:</div>
                <div class="value"><?php echo $order['priority'] == 'High' ? '🔥 '._l('high') : _l('normal'); ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title"><?php echo _l('appearance'); ?></div>
        <div class="problem-box" style="min-height: 40px;">
            <?php echo nl2br(htmlspecialchars($order['appearance'] ?: _l('not_found'))); ?>
        </div>
    </div>

    <div class="section">
        <div class="section-title"><?php echo mb_strtoupper(_l('problem')); ?></div>
        <div class="problem-box">
            <?php echo nl2br(htmlspecialchars($order['problem_description'])); ?>
        </div>
    </div>

    <?php if(!empty($order['technician_notes'])): ?>
    <div class="section">
        <div class="section-title"><?php echo mb_strtoupper(_l('notes')); ?></div>
        <div class="problem-box" style="min-height: 40px; border-style: dashed;">
            <?php echo nl2br(htmlspecialchars($order['technician_notes'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title"><?php echo mb_strtoupper(_l('action')); ?> (doplní technik)</div>
        <div style="border: 1px solid #000; height: 150px;"></div>
    </div>

    <div class="grid" style="margin-top: 20px;">
        <div>
            <div class="label"><?php echo mb_strtoupper(_l('technician')); ?>:</div>
            <div class="value"><?php echo htmlspecialchars($order['tech_name'] ?: '____________________'); ?></div>
        </div>
        <div style="text-align: right;">
            <div class="label"><?php echo mb_strtoupper(_l('save')); ?>:</div>
            <div style="margin-top: 10px;">____________________</div>
        </div>
    </div>

    <div class="footer">
        * Pracovní příkaz je interní dokument servisního centra.
        * QR kód vede na elektronickou kartu zakázky.
    </div>
</div>

</body>
</html>
