<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("ID zakázky není zadáno");

$id = $_GET['id'] ?? $_GET['order_id'];
$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Zakázka nenalezena");

// Fetch items (parts) used
$stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$currency = get_setting('currency', 'Kč');

$target_lang = 'cs';
function _l($key) { return __($key, 'cs'); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Účtenka #<?php echo $order['id']; ?></title>
    <style>
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 14px; 
            width: 72mm; /* Actual printable area for 80mm paper */
            margin: 0; 
            padding: 0;
            color: #000;
            background: #fff;
        }
        .container {
            width: 100%;
            padding: 1mm;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .bold { font-weight: 900; }
        .line { border-bottom: 2px dashed #000; margin: 8px 0; }
        .header { font-size: 18px; margin-bottom: 5px; text-transform: uppercase; }
        .order-num { font-size: 22px; margin: 5px 0; }
        .item-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .item-name { flex: 1; padding-right: 5px; font-weight: bold; }
        .footer { font-size: 12px; margin-top: 15px; border-top: 1px solid #000; padding-top: 5px; font-weight: bold; }
        .qr-code { margin-top: 10px; }
        .qr-code img { width: 40mm; height: 40mm; }
        
        @media print {
            @page { 
                margin: 0; 
                size: 80mm auto; 
            }
            body { width: 72mm; background: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body<?php if (empty($_GET['embed'])): ?> onload="window.print()"<?php endif; ?>>

<div class="container">
    <div class="text-center">
        <div class="header bold"><?php echo htmlspecialchars(get_setting('company_name', 'Repair CRM')); ?></div>
        <div style="font-size: 12px;"><?php echo htmlspecialchars(get_setting('company_address')); ?></div>
        <div><?php echo _l('phone'); ?>: <?php echo htmlspecialchars(get_setting('company_phone')); ?></div>
        <div class="line"></div>
        <div class="order-num bold"><?php echo mb_strtoupper(_l('order')); ?> č. <?php echo $order['id']; ?></div>
        <div class="bold"><?php echo mb_strtoupper(_l('collected')); ?></div>
        <div><?php echo date('d.m.Y H:i'); ?></div>
        <div class="line"></div>
    </div>

    <div class="bold"><?php echo mb_strtoupper(_l('client')); ?>:</div>
    <div><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
    <div><?php echo _l('phone'); ?>: <?php echo htmlspecialchars($order['phone']); ?></div>

    <div class="line"></div>

    <div class="bold"><?php echo mb_strtoupper(_l('device_type')); ?>:</div>
    <div class="bold" style="font-size: 16px;"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
    <div>S/N: <?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?></div>
    <?php if($order['pin_code']): ?>
    <div><?php echo _l('pin'); ?>: <span class="bold"><?php echo htmlspecialchars($order['pin_code']); ?></span></div>
    <?php endif; ?>

    <div class="line"></div>

    <div class="bold"><?php echo mb_strtoupper(_l('problem')); ?>:</div>
    <div style="word-wrap: break-word; font-style: italic;"><?php echo htmlspecialchars($order['problem_description']); ?></div>

    <div class="line"></div>

    <div class="bold"><?php echo mb_strtoupper(_l('parts_used')); ?>:</div>
    <div class="item-row">
        <span class="item-name"><?php echo _l('work_cost'); ?></span>
        <span class="bold"><?php echo formatMoney($order['final_cost'] ?? $order['estimated_cost']); ?></span>
    </div>
    <?php foreach ($items as $item): ?>
    <div class="item-row">
        <span class="item-name"><?php echo htmlspecialchars($item['part_name']); ?> x<?php echo $item['quantity']; ?></span>
        <span><?php echo formatMoney($item['price'] * $item['quantity']); ?></span>
    </div>
    <?php endforeach; ?>

    <div class="line" style="border-bottom-style: solid;"></div>

    <div class="text-right">
        <div class="bold" style="font-size: 12px;"><?php echo mb_strtoupper(_l('total_pay')); ?>:</div>
        <div class="bold" style="font-size: 22px;"><?php 
            $total = ($order['final_cost'] ?? $order['estimated_cost']);
            foreach ($items as $item) $total += ($item['price'] * $item['quantity']);
            echo formatMoney($total);
        ?></div>
    </div>

    <div class="line"></div>

    <div class="text-center qr-code">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode('https://servis.expert/status/?id='.$order['id']); ?>" alt="QR">
        <div style="font-size: 10px; margin-top: 5px;">Ověřit stav zakázky</div>
    </div>

    <div class="footer text-center">
        Prosím, uchovejte tuto účtenku do dokončení opravy.<br>
        <div class="bold" style="margin-top: 5px;">www.servis.expert</div>
    </div>
</div>

<div class="no-print text-center" style="margin-top: 20px; padding-bottom: 50px;">
    <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px;">Tisk účtenky</button>
</div>

<div class="footer text-center">
    Děkujeme za Vaši důvěru!<br>
    www.servis.expert
</div>

<div class="no-print text-center" style="margin-top: 20px;">
    <button onclick="window.print()">Tisk</button>
</div>

</body>
</html>
