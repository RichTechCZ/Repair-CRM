    </main>

    <footer class="app-footer">
        <p>&copy; <?php echo date('Y'); ?> Repair CRM - <?php echo __('system_title'); ?></p>
    </footer>
</div> <!-- /#content -->

<div id="appStatusLive" class="visually-hidden" aria-live="polite" aria-atomic="true"></div>

<!-- Universal Preview Modal -->
<div class="modal fade" id="universalPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-secondary py-2">
                <h6 class="modal-title mb-0" id="universalPreviewTitle"><i class="fas fa-file-alt me-2 text-primary"></i>Preview</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="max-height: 85vh; overflow-y: auto; background: #f5f5f5;">
                <div id="universalPreviewContent"></div>
            </div>
            <div class="modal-footer border-secondary py-2">
                <a href="#" id="previewOpenTabBtn" target="_blank" class="btn btn-outline-secondary btn-sm me-auto" data-crm-action="open-preview-new-tab">
                    <i class="fas fa-external-link-alt me-1"></i><?php echo __('open_full_view'); ?>
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?php echo __('close'); ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="previewPrintBtn" disabled data-crm-action="print-preview">
                    <i class="fas fa-print me-1"></i><?php echo __('print'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Global Alert Modal -->
<div class="modal fade" id="globalAlertModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="globalAlertTitle"><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="globalAlertBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Global Confirm Modal -->
<div class="modal fade" id="globalConfirmModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="globalConfirmTitle"><?php echo __('confirm_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="globalConfirmBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="globalConfirmCancel"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="globalConfirmOk"><?php echo __('confirm'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php
if (!empty($nav_items) && isset($_SESSION['user_id'])):
    $tab_visible = [];
    foreach ($nav_items as $tab_item) {
        if (!empty($tab_item['visible'])) {
            $tab_visible[basename($tab_item['href'])] = $tab_item;
        }
    }
    $tab_icons = [
        'customers.php' => 'fa-user-group',
        'inventory.php' => 'fa-boxes-stacked',
        'reports.php' => 'fa-chart-line',
        'statistics.php' => 'fa-chart-pie',
    ];
    $tab_last = null;
    foreach (['customers.php', 'inventory.php', 'reports.php', 'statistics.php'] as $tab_candidate) {
        if (isset($tab_visible[$tab_candidate])) {
            $tab_last = $tab_visible[$tab_candidate];
            break;
        }
    }
    $tab_current = basename($_SERVER['PHP_SELF']);
    $tab_last_file = $tab_last ? basename($tab_last['href']) : 'settings.php';
?>
<nav class="ios-tabbar d-lg-none" aria-label="<?php echo e(__('menu')); ?>">
    <a class="ios-tabbar__item<?php echo $tab_current === 'index.php' ? ' is-active' : ''; ?>" href="index.php"<?php echo $tab_current === 'index.php' ? ' aria-current="page"' : ''; ?>>
        <i class="fas fa-house" aria-hidden="true"></i><span><?php echo e(__('dashboard')); ?></span>
    </a>
    <a class="ios-tabbar__item<?php echo $tab_current === 'orders.php' ? ' is-active' : ''; ?>" href="orders.php"<?php echo $tab_current === 'orders.php' ? ' aria-current="page"' : ''; ?>>
        <i class="fas fa-screwdriver-wrench" aria-hidden="true"></i><span><?php echo e(__('orders')); ?></span>
    </a>
    <a class="ios-tabbar__item ios-tabbar__item--primary" href="orders.php?new_order=1" data-tab-action="new-order" aria-label="<?php echo e(__('new_order')); ?>">
        <i class="fas fa-plus" aria-hidden="true"></i><span><?php echo e(__('new_order')); ?></span>
    </a>
    <a class="ios-tabbar__item<?php echo $tab_current === $tab_last_file ? ' is-active' : ''; ?>" href="<?php echo e($tab_last ? $tab_last['href'] : 'settings.php'); ?>"<?php echo $tab_current === $tab_last_file ? ' aria-current="page"' : ''; ?>>
        <i class="fas <?php echo e($tab_icons[$tab_last_file] ?? 'fa-gear'); ?>" aria-hidden="true"></i><span><?php echo e($tab_last ? $tab_last['label'] : __('settings')); ?></span>
    </a>
    <button type="button" class="ios-tabbar__item" data-tab-action="more" aria-controls="sidebar" aria-expanded="false">
        <i class="fas fa-bars" aria-hidden="true"></i><span><?php echo e(__('menu')); ?></span>
    </button>
</nav>
<?php endif; ?>

</body>
</html>
