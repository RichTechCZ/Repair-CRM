<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) die("Unauthorized access");
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

$target_lang = 'cs';
function _l($key) {
    return __($key, 'cs');
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Potvrzení o zakázce #<?php echo $order['id']; ?></title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333; margin: 0; padding: 20px; }
        .receipt-box { max-width: 800px; margin: auto; border: 1px solid #eee; padding: 30px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.15); }
        .header { display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 20px; }
        .header h1 { margin: 0; color: #333; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .info-col { flex: 1; }
        .table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .table th { background-color: #f2f2f2; }
        .total { text-align: right; font-size: 18px; font-weight: bold; margin-top: 20px; }
        .footer { margin-top: 50px; border-top: 1px solid #eee; pt: 20px; font-size: 12px; }
        .signatures { display: flex; justify-content: space-between; margin-top: 40px; }
        .sig-line { border-top: 1px solid #333; width: 200px; text-align: center; margin-top: 20px; }
        @media print {
            .no-print { display: none; }
            .receipt-box { box-shadow: none; border: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin-bottom: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px;"><?php echo _l('print'); ?></button>
</div>

<div class="receipt-box">
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars(get_setting('company_name', 'Repair CRM')); ?></h1>
            <p><?php echo nl2br(htmlspecialchars(get_setting('company_address'))); ?><br>
               <?php echo _l('phone'); ?>: <?php echo htmlspecialchars(get_setting('company_phone')); ?></p>
        </div>
        <div style="text-align: right;">
            <h2><?php echo mb_strtoupper(_l('order')); ?> č. <?php echo $order['id']; ?></h2>
            <p><?php echo _l('created'); ?>: <?php echo date('d.m.Y', strtotime($order['created_at'])); ?></p>
        </div>
    </div>

    <div class="info-row">
        <div class="info-col">
            <strong><?php echo mb_strtoupper(_l('client')); ?>:</strong><br>
            <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?><br>
            <?php echo _l('phone'); ?>: <?php echo htmlspecialchars($order['phone']); ?><br>
            <?php echo htmlspecialchars($order['address']); ?>
        </div>
        <div class="info-col" style="text-align: right;">
            <strong><?php echo mb_strtoupper(_l('device_model')); ?>:</strong><br>
            <?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?><br>
            <?php echo htmlspecialchars($order['device_type']); ?> | S/N: <?php echo htmlspecialchars($order['serial_number']); ?>
            <?php if($order['serial_number_2']): ?><br><?php echo _l('serial_2'); ?>: <?php echo htmlspecialchars($order['serial_number_2']); ?><?php endif; ?>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <strong><?php echo _l('problem'); ?>:</strong><br>
        <?php echo nl2br(htmlspecialchars($order['problem_description'])); ?>
    </div>

    <?php if($order['appearance']): ?>
    <div style="margin-top: 10px;">
        <strong><?php echo _l('appearance'); ?>:</strong><br>
        <?php echo htmlspecialchars($order['appearance']); ?>
    </div>
    <?php endif; ?>

    <?php if($order['pin_code']): ?>
    <div style="margin-top: 10px;">
        <strong><?php echo _l('pin'); ?>:</strong><br>
        <code><?php echo htmlspecialchars($order['pin_code']); ?></code>
    </div>
    <?php endif; ?>

    <table class="table">
        <thead>
            <tr>
                <th><?php echo _l('part_name'); ?></th>
                <th><?php echo _l('quantity'); ?></th>
                <th><?php echo _l('buy_price'); ?></th>
                <th><?php echo _l('sum'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo _l('work_cost'); ?></td>
                <td>1</td>
                <td><?php echo formatMoney((float)($order['final_cost'] ?: $order['estimated_cost'])); ?></td>
                <td><?php echo formatMoney((float)($order['final_cost'] ?: $order['estimated_cost'])); ?></td>
            </tr>
            <?php foreach ($items as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                <td><?php echo $item['quantity']; ?></td>
                <td><?php echo formatMoney($item['price']); ?></td>
                <td><?php echo formatMoney($item['price'] * $item['quantity']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="total">
        <?php echo mb_strtoupper(_l('total_pay')); ?>: <?php 
            $total = (float)($order['final_cost'] ?: $order['estimated_cost']);
            foreach ($items as $item) $total += ($item['price'] * $item['quantity']);
            echo formatMoney($total);
        ?>
    </div>

    <div class="signatures">
        <div>
            <div class="sig-line">Podpis zákazníka</div>
        </div>
        <div>
            <div class="sig-line">Převzal (razítko)</div>
        </div>
    </div>

    <div class="footer">
        <p>Záruka na provedené práce je 30 dní. Záruka se nevztahuje na poškození způsobená nesprávným používáním zařízení.</p>
    </div>
</div>

</body>
</html>
