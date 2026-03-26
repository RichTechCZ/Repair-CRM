<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("ID zakázky není zadáno");

$id = $_GET['id'] ?? $_GET['order_id'];
$target_lang = $_GET['lang'] ?? 'cs';

// Helper for local translations
function _l($key) {
    global $target_lang;
    return __($key, $target_lang);
}

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die("Заказ не найден");

$currency = get_setting('currency', 'Kč');
?>
<!DOCTYPE html>
<html lang="<?php echo $target_lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo _l('reception_act'); ?> #<?php echo $order['id']; ?></title>
    <style>
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 14px; 
            width: 72mm; 
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
        .header { font-size: 18px; margin-bottom: 3px; text-transform: uppercase; }
        .order-num { font-size: 22px; margin: 5px 0; }
        .item-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
        .label { color: #000; font-size: 12px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px; }
        .footer { font-size: 12px; margin-top: 15px; border-top: 1px solid #000; padding-top: 5px; font-weight: bold; }
        .qr-code { margin-top: 10px; }
        .qr-code img { width: 40mm; height: 40mm; }
        .terms { font-size: 11px; line-height: 1.2; margin-top: 15px; font-style: italic; }
        
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
        <div style="font-size: 11px;"><?php echo htmlspecialchars(get_setting('company_address')); ?></div>
        <div><?php echo _l('phone'); ?>: <?php echo htmlspecialchars(get_setting('company_phone')); ?></div>
        <div class="line"></div>
        <div class="order-num bold"><?php echo mb_strtoupper(_l('order')); ?> №<?php echo $order['id']; ?></div>
        <div class="bold"><?php echo mb_strtoupper(_l('created')); ?>: <?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></div>
        <div class="line"></div>
    </div>

    <div class="label"><?php echo _l('client'); ?>:</div>
    <div class="bold"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
    <div><?php echo _l('phone'); ?>: <?php echo htmlspecialchars($order['phone']); ?></div>

    <div class="line"></div>

    <div class="label"><?php echo _l('device_model'); ?>:</div>
    <div class="bold" style="font-size: 15px;"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
    
    <div class="item-row mt-1">
        <span>S/N:</span>
        <span class="bold"><?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?></span>
    </div>
    
    <?php if($order['pin_code']): ?>
    <div class="item-row">
        <span><?php echo _l('pin'); ?>:</span>
        <span class="bold"><?php echo htmlspecialchars($order['pin_code']); ?></span>
    </div>
    <?php endif; ?>

    <div class="line"></div>

    <div class="label"><?php echo _l('appearance'); ?>:</div>
    <div style="word-wrap: break-word;"><?php echo htmlspecialchars($order['appearance'] ?: '---'); ?></div>

    <div class="line"></div>

    <div class="label"><?php echo _l('problem'); ?>:</div>
    <div style="word-wrap: break-word; font-style: italic;"><?php echo htmlspecialchars($order['problem_description']); ?></div>

    <div class="line"></div>

    <div class="item-row">
        <span class="bold"><?php echo mb_strtoupper(_l('cost_est')); ?>:</span>
        <span class="bold" style="font-size: 16px;"><?php echo formatMoney($order['estimated_cost']); ?></span>
    </div>

    <div class="line"></div>

    <div class="text-center qr-code">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode('https://servis.expert/status/?id='.$order['id']); ?>" alt="QR">
        <div style="font-size: 10px; margin-top: 3px;">Status: servis.expert/status</div>
    </div>

    <div class="terms text-center">
        Zakázka je přijata k opravě. Diagnostika může být zpoplatněna. 
        Záruka na práci je 6 měsíců. Skladování nad 30 dní je zpoplatněno.
    </div>

    <div class="footer text-center">
        <?php echo _l('reception_act'); ?> #<?php echo $order['id']; ?><br>
        <div class="bold" style="margin-top: 3px;">www.servis.expert</div>
    </div>
</div>

<div class="no-print text-center" style="margin-top: 20px; padding-bottom: 30px;">
    <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px;">Tisk</button>
    <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; margin-left: 10px;">Zavřít</button>
</div>

</body>
</html>
