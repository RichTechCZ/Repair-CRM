<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
if (!isset($_GET['id'])) die("<?php echo __('missing_id'); ?>");

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic
                       FROM invoices i 
                       JOIN customers c ON i.customer_id = c.id 
                       WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) die("<?php echo __('print_not_found'); ?>");

// Fetch invoice items
$stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Payment method translations
$payment_methods = [
    'bank_transfer' => 'Bankovní převod',
    'cash' => 'Hotovost',
    'card' => 'Kartou',
    'cod' => 'Dobírka'
];
$payment_method = $payment_methods[$invoice['payment_method']] ?? $invoice['payment_method'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?php echo __('print_title_receipt'); ?> <?php echo $invoice['invoice_number']; ?></title>
    <style>
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 13px; 
            line-height: 1.2; 
            margin: 0; 
            padding: 0;
            background: #fff;
            color: #000;
        }
        .receipt {
            width: 72mm; /* Better compatibility for 80mm printers */
            padding: 2mm;
            margin: 0 auto;
            background: white;
        }
        .header { text-align: center; border-bottom: 2px dashed #000; padding-bottom: 8px; margin-bottom: 8px; }
        .company-name { font-size: 16px; font-weight: 900; text-transform: uppercase; }
        .doc-title { font-size: 20px; font-weight: 900; margin: 5px 0; }
        
        .section { margin-bottom: 8px; padding-bottom: 5px; border-bottom: 1px dashed #000; }
        .row { display: flex; justify-content: space-between; margin-bottom: 2px; }
        .label { font-weight: bold; color: #000; }
        
        .items { margin: 8px 0; }
        .item { margin-bottom: 6px; }
        .item-name { font-weight: bold; font-size: 14px; display: block; }
        .item-details { display: flex; justify-content: space-between; font-size: 12px; }
        .item-price { text-align: right; font-weight: bold; }
        
        .total-section { border-top: 3px solid #000; padding-top: 8px; margin-top: 8px; }
        .total-row { display: flex; justify-content: space-between; font-size: 22px; font-weight: 900; }
        
        .footer { text-align: center; margin-top: 15px; font-size: 12px; font-weight: bold; color: #000; border-top: 1px solid #000; padding-top: 5px; }
        .barcode { text-align: center; margin: 10px 0; font-size: 20px; letter-spacing: 3px; font-weight: bold; }
        
        @media print {
            body { background: none; }
            .receipt { width: 72mm; margin: 0; padding: 0; }
            .no-print { display: none; }
            @page { margin: 0; size: 80mm auto; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #28a745; color: white; border: none; border-radius: 4px;"><?php echo __('print_btn'); ?></button>
    <a href="accounting.php" style="padding: 10px 20px; text-decoration: none; background: #6c757d; color: white; border-radius: 4px; margin-left: 10px;"><?php echo __('back'); ?></a>
</div>

<div class="receipt">
    <div class="header">
        <div class="company-name"><?php echo htmlspecialchars(get_setting('acc_company_name')); ?></div>
        <div><?php echo nl2br(htmlspecialchars(get_setting('acc_address'))); ?></div>
        <div>IČO: <?php echo htmlspecialchars(get_setting('acc_ico')); ?></div>
        <?php if(get_setting('acc_dic')): ?>
        <div>DIČ: <?php echo htmlspecialchars(get_setting('acc_dic')); ?></div>
        <?php endif; ?>
    </div>

    <div class="doc-title" style="text-align: center;">
        <?php echo ($invoice['invoice_type'] == 'credit_note') ? 'DOBROPIS' : 'ÚČTENKA'; ?>
    </div>
    <div style="text-align: center; margin-bottom: 10px;">
        č. <?php echo $invoice['invoice_number']; ?>
    </div>

    <div class="section">
        <div class="row"><span class="label">Datum:</span><span><?php echo date('d.m.Y H:i', strtotime($invoice['created_at'])); ?></span></div>
        <div class="row"><span class="label">Var. symbol:</span><span><?php echo $invoice['variable_symbol']; ?></span></div>
        <div class="row"><span class="label">Platba:</span><span><?php echo $payment_method; ?></span></div>
    </div>

    <div class="section">
        <div class="label">Odběratel:</div>
        <div><strong><?php echo htmlspecialchars($invoice['company'] ?: $invoice['first_name'] . ' ' . $invoice['last_name']); ?></strong></div>
        <?php if($invoice['ico']): ?><div>IČO: <?php echo $invoice['ico']; ?></div><?php endif; ?>
    </div>

    <div class="items">
        <div style="border-bottom: 1px solid #000; padding-bottom: 3px; margin-bottom: 5px;"><strong>Položky:</strong></div>
        <?php foreach($items as $item): ?>
        <div class="item">
            <div class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
            <div class="item-details">
                <span><?php echo $item['quantity']; ?> <?php echo $item['unit']; ?> × <?php echo number_format($item['price'], 2, ',', ' '); ?></span>
                <span class="item-price"><?php echo number_format($item['quantity'] * $item['price'], 2, ',', ' '); ?> <?php echo $invoice['currency'] ?: 'Kč'; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if(empty($items)): ?>
        <div class="item">
            <div class="item-name"><?php echo htmlspecialchars($invoice['notes'] ?: 'Služba'); ?></div>
            <div class="item-details">
                <span>1 ks</span>
                <span class="item-price"><?php echo number_format($invoice['total_amount'], 2, ',', ' '); ?> <?php echo $invoice['currency'] ?: 'Kč'; ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="total-section">
        <div class="total-row">
            <span>CELKEM:</span>
            <span><?php echo number_format($invoice['total_amount'], 2, ',', ' '); ?> <?php echo $invoice['currency'] ?: 'Kč'; ?></span>
        </div>
    </div>

    <div class="barcode">
        *<?php echo $invoice['variable_symbol']; ?>*
    </div>

    <div class="footer">
        <div>Děkujeme za Vaši důvěru!</div>
        <div style="margin-top: 5px;"><?php echo get_setting('company_phone'); ?></div>
    </div>
</div>

</body>
</html>
