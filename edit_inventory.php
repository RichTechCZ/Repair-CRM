<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die(__("inventory_id_missing"));
}

$stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die(__("part_not_found"));
}

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die(__('csrf_token_invalid'));
    }

    $part_name = trim((string)($_POST['part_name'] ?? ''));
    $sku = trim((string)($_POST['sku'] ?? ''));
    $quantity_raw = $_POST['quantity'] ?? '';
    $cost_raw = $_POST['cost_price'] ?? '';
    $sale_raw = $_POST['sale_price'] ?? '';
    $min_raw = $_POST['min_stock'] ?? '';

    if ($part_name === '') {
        $error = __('part_name') . ': ' . __('missing_data');
    } elseif (!is_numeric($quantity_raw) || !is_finite((float)$quantity_raw) || (float)$quantity_raw < 0 || floor((float)$quantity_raw) != (float)$quantity_raw) {
        $error = __('stock_quantity') . ': ' . __('missing_data');
    } elseif ($cost_raw !== '' && (!is_numeric($cost_raw) || !is_finite((float)$cost_raw) || (float)$cost_raw < 0)) {
        $error = __('buy_price') . ': ' . __('missing_data');
    } elseif ($sale_raw !== '' && (!is_numeric($sale_raw) || !is_finite((float)$sale_raw) || (float)$sale_raw < 0)) {
        $error = __('sell_price') . ': ' . __('missing_data');
    } elseif ($min_raw !== '' && (!is_numeric($min_raw) || !is_finite((float)$min_raw) || (float)$min_raw < 0 || floor((float)$min_raw) != (float)$min_raw)) {
        $error = __('min_stock_alert_limit') . ': ' . __('missing_data');
    } else {
        $quantity = (int)$quantity_raw;
        $cost_price = $cost_raw === '' ? 0.0 : (float)$cost_raw;
        $sale_price = $sale_raw === '' ? 0.0 : (float)$sale_raw;
        $min_stock = $min_raw === '' ? 0 : (int)$min_raw;

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
            $stmt->execute([$id]);
            $item = $stmt->fetch();
        } catch (Exception $e) {
            error_log('edit_inventory error: ' . $e->getMessage());
            $error = __("error_prefix") . publicExceptionMessage($e);
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo __('edit_product_title'); ?> <?php echo htmlspecialchars($item['part_name']); ?></h2>
    <a href="inventory.php" class="btn btn-outline-secondary"><?php echo __('back_to_inventory'); ?></a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card surface-card">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label"><?php echo __('part_name'); ?></label>
                    <input type="text" name="part_name" class="form-control" value="<?php echo htmlspecialchars($item['part_name']); ?>" required maxlength="255">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sku'); ?></label>
                    <input type="text" name="sku" class="form-control" value="<?php echo htmlspecialchars($item['sku']); ?>" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('stock_quantity'); ?></label>
                    <input type="number" name="quantity" class="form-control" value="<?php echo (int)$item['quantity']; ?>" min="0" step="1" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('buy_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="cost_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$item['cost_price']); ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('sell_price'); ?></label>
                    <div class="input-group">
                        <input type="number" name="sale_price" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars((string)$item['sale_price']); ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><?php echo __('min_stock_alert_limit'); ?></label>
                    <input type="number" name="min_stock" class="form-control" min="0" step="1" value="<?php echo (int)$item['min_stock']; ?>">
                </div>
                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
