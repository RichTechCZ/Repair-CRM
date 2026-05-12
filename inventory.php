<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Pagination and Filters
$limit = 20;
$page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = $_GET['search'] ?? '';
$min_price = $_GET['min_price'] ?? '';
$max_price = $_GET['max_price'] ?? '';

$where_clauses = [];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "(part_name LIKE ? OR sku LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($min_price !== '') {
    $where_clauses[] = "sale_price >= ?";
    $params[] = floatval($min_price);
}

if ($max_price !== '') {
    $where_clauses[] = "sale_price <= ?";
    $params[] = floatval($max_price);
}

$where_sql = $where_clauses ? " WHERE " . implode(" AND ", $where_clauses) : "";

$total_items = $pdo->prepare("SELECT COUNT(*) FROM inventory" . $where_sql);
$total_items->execute($params);
$total_count = $total_items->fetchColumn();

$total_pages = ceil($total_count / $limit);

$stmt = $pdo->prepare("SELECT * FROM inventory" . $where_sql . " ORDER BY part_name ASC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$inventory = $stmt->fetchAll();

$inventory_stats = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN quantity <= min_stock THEN 1 ELSE 0 END) as low_stock FROM inventory")->fetch();
$catalog_url = trim((string)get_setting('inventory_catalog_url', ''));
$catalog_host = '';
if ($catalog_url !== '') {
    $catalog_host = (string)parse_url($catalog_url, PHP_URL_HOST);
}

$catalog_error_key = $_GET['catalog_error'] ?? '';
$catalog_error_message = '';
if ($catalog_error_key !== '') {
    $catalog_error_map = [
        'invalid_url' => __('catalog_error_invalid_url'),
        'fetch_failed' => __('catalog_error_fetch_failed'),
        'no_products' => __('catalog_error_no_products'),
        'processing_failed' => __('catalog_error_processing_failed'),
    ];
    $catalog_error_message = $catalog_error_map[$catalog_error_key] ?? __('catalog_error_processing_failed');
}

$catalog_added = isset($_GET['catalog_added']) ? max(0, (int)$_GET['catalog_added']) : 0;
$catalog_updated = isset($_GET['catalog_updated']) ? max(0, (int)$_GET['catalog_updated']) : 0;
$catalog_import_success = isset($_GET['catalog_imported']);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
        <small class="text-muted"><?php echo __('total_items'); ?>: <?php echo $total_count; ?></small>
        <?php if ($catalog_host !== ''): ?>
            <div class="small text-muted mt-1"><?php echo __('catalog_saved_source'); ?>: <?php echo htmlspecialchars($catalog_host); ?></div>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if($inventory_stats['low_stock'] > 0): ?>
            <span class="badge bg-warning text-dark me-2"><?php echo __('low_stock_alert'); ?>: <?php echo $inventory_stats['low_stock']; ?></span>
        <?php endif; ?>
        <button class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#filterPanel">
            <i class="fas fa-filter me-2"></i> <?php echo __('filters'); ?>
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPartModal">
            <i class="fas fa-plus me-2"></i> <?php echo __('add_part'); ?>
        </button>
        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#catalogUpdateModal">
            <i class="fas fa-sync me-2"></i> <?php echo __('update_catalog'); ?>
        </button>
    </div>
</div>

<?php if ($catalog_import_success): ?>
    <div class="alert alert-success shadow-sm border-0">
        <?php echo sprintf(__('catalog_update_success'), $catalog_added, $catalog_updated); ?>
    </div>
<?php endif; ?>

<?php if ($catalog_error_message !== ''): ?>
    <div class="alert alert-danger shadow-sm border-0">
        <?php echo htmlspecialchars($catalog_error_message); ?>
    </div>
<?php endif; ?>

<div class="collapse mb-4 <?php echo (!empty($search) || !empty($min_price) || !empty($max_price)) ? 'show' : ''; ?>" id="filterPanel">
    <div class="card card-body shadow-sm">
        <form action="inventory.php" method="GET" class="row g-3">
            <div class="col-md-5">
                <label class="form-label small"><?php echo __('search_sku_placeholder'); ?></label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="<?php echo __('name_or_sku'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small"><?php echo __('price_from'); ?></label>
                <input type="number" name="min_price" class="form-control form-control-sm" value="<?php echo htmlspecialchars($min_price); ?>" step="0.01">
            </div>
            <div class="col-md-2">
                <label class="form-label small"><?php echo __('price_to'); ?></label>
                <input type="number" name="max_price" class="form-control form-control-sm" value="<?php echo htmlspecialchars($max_price); ?>" step="0.01">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?php echo __('apply_btn'); ?></button>
                <a href="inventory.php" class="btn btn-sm btn-outline-secondary"><?php echo __('reset_btn'); ?></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-4"><?php echo __('photo_col'); ?></th>
                                <th><?php echo __('part_name'); ?></th>
                                <th><?php echo __('sku'); ?></th>
                                <th><?php echo __('quantity'); ?></th>
                                <th><?php echo __('buy_price'); ?></th>
                                <th><?php echo __('sell_price'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="text-end pe-4"><?php echo __('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventory)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-boxes fa-3x mb-3 d-block opacity-25"></i>
                                        <?php echo __('stock_empty'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inventory as $item): ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if(!empty($item['image_path'])): ?>
                                            <a href="<?php echo $item['image_path']; ?>" data-fancybox="inventory">
                                                <img src="<?php echo $item['image_path']; ?>" class="rounded shadow-sm" style="width: 40px; height: 40px; object-fit: cover;">
                                            </a>
                                        <?php else: ?>
                                            <div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center shadow-sm border border-secondary" style="width: 40px; height: 40px;">
                                                <i class="fas fa-image text-muted opacity-25"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($item['part_name']); ?></div>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                    <td>
                                        <span class="fw-medium <?php echo $item['quantity'] <= $item['min_stock'] ? 'text-danger' : ''; ?>">
                                            <?php echo $item['quantity']; ?> <?php echo __('pcs_short'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatMoney($item['cost_price']); ?></td>
                                    <td class="fw-bold text-primary"><?php echo formatMoney($item['sale_price']); ?></td>
                                    <td>
                                        <?php if ($item['quantity'] <= 0): ?>
                                            <span class="badge bg-danger"><?php echo __('status_no'); ?></span>
                                        <?php elseif ($item['quantity'] <= $item['min_stock']): ?>
                                            <span class="badge bg-warning text-dark"><?php echo __('status_low'); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo __('status_ok'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm">
                                            <a href="edit_inventory.php?id=<?php echo $item['id']; ?>" class="btn btn-white border" title="<?php echo __('edit'); ?>"><i class="fas fa-edit text-warning"></i></a>
                                            <button type="button" class="btn btn-white border text-danger" onclick="deletePart(<?php echo $item['id']; ?>)" title="<?php echo __('delete'); ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php 
                $params_p = $_GET;
                unset($params_p['p']);
                $qs = http_build_query($params_p);
                $url_pre = $qs ? "&$qs" : "";
                ?>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo $page-1 . $url_pre; ?>"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i . $url_pre; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo $page+1 . $url_pre; ?>"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="newPartModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="api/add_inventory.php" method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('add_part'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label"><?php echo __('part_name'); ?></label>
                            <input type="text" name="part_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('sku'); ?></label>
                            <input type="text" name="sku" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('quantity'); ?></label>
                            <input type="number" name="quantity" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('buy_price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="cost_price" class="form-control" step="0.01">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('sell_price'); ?></label>
                            <div class="input-group">
                                <input type="number" name="sale_price" class="form-control" step="0.01">
                                <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label"><?php echo __('min_stock_label'); ?></label>
                            <input type="number" name="min_stock" class="form-control" value="5">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="catalogUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="catalogUpdateForm" action="api/parse_catalog.php" method="POST" onsubmit="return confirmCatalogUpdate(this);">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('update_catalog'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo __('cancel'); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="catalogUrl" class="form-label"><?php echo __('catalog_url_label'); ?></label>
                        <input
                            type="url"
                            id="catalogUrl"
                            name="catalog_url"
                            class="form-control"
                            value="<?php echo htmlspecialchars($catalog_url); ?>"
                            placeholder="<?php echo __('catalog_url_placeholder'); ?>"
                            required
                        >
                        <div class="form-text"><?php echo __('catalog_url_help'); ?></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sync me-2"></i><?php echo __('update_catalog'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2-filter').select2({
        width: '100%',
        dropdownParent: $('#filterPanel')
    });
});

function deletePart(id) {
    showConfirm('<?php echo __('confirm_delete_inventory'); ?>', function() {
        $.post('api/delete_inventory.php', {id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'}, function(res) {
            if (res.success) {
                location.reload();
            } else {
                showAlert('<?php echo __('error_prefix'); ?>' + res.message);
            }
        });
    });
}

function confirmCatalogUpdate(form) {
    const urlInput = form.querySelector('[name="catalog_url"]');
    if (!urlInput || !urlInput.value.trim()) {
        return false;
    }

    showConfirm('<?php echo __('parse_confirm'); ?>', function() {
        form.submit();
    });

    return false;
}
</script>



<?php require_once 'includes/footer.php'; ?>

