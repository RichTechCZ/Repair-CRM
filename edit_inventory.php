<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) die(__("inventory_id_missing"));

$stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) die(__("part_not_found"));

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $part_name = $_POST['part_name'];
    $sku = $_POST['sku'];
    $quantity = $_POST['quantity'];
    $cost_price = $_POST['cost_price'];
    $sale_price = $_POST['sale_price'];
    $min_stock = $_POST['min_stock'];

    try {
        $update = $pdo->prepare("UPDATE inventory SET 
            part_name = ?, 
            sku = ?, 
            quantity = ?, 
            cost_price = ?, 
            sale_price = ?, 
            min_stock = ? 
            WHERE id = ?");
        $update->execute([$part_name, $sku, $quantity, $cost_price, $sale_price, $min_stock, $id]);
        $success = __("inventory_updated");
        // Refresh
        $stmt->execute([$id]);
        $item = $stmt->fetch();
    } catch (Exception $e) {
        $error = __("error_prefix") . $e->getMessage();
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo __('edit_product_title'); ?> <?php echo htmlspecialchars($item['part_name']); ?></h2>
    <a href="inventory.php" class="btn btn-outline-secondary"><?php echo __('back_to_inventory'); ?></a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label"><?php echo __('part_name'); ?></label>
                    <input type="text" name="part_name" class="form-control" value="<?php echo htmlspecialchars($item['part_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sku'); ?></label>
                    <input type="text" name="sku" class="form-control" value="<?php echo htmlspecialchars($item['sku']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('stock_quantity'); ?></label>
                    <input type="number" name="quantity" class="form-control" value="<?php echo $item['quantity']; ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('buy_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="cost_price" class="form-control" step="0.01" value="<?php echo $item['cost_price']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sell_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="sale_price" class="form-control" step="0.01" value="<?php echo $item['sale_price']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><?php echo __('min_stock_alert_limit'); ?></label>
                    <input type="number" name="min_stock" class="form-control" value="<?php echo $item['min_stock']; ?>">
                </div>
                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
