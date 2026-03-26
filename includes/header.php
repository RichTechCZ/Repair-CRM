<?php
require_once __DIR__ . '/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header("Location: login.php");
    exit;
}

// Access Control based on permissions
$page = basename($_SERVER['PHP_SELF']);

// Pages that require specific permissions
$permission_pages = [
    'customers.php' => 'edit_customers',
    'edit_customer.php' => 'edit_customers',
    'inventory.php' => 'admin_access',
    // 'reports.php' => 'admin_access', // Handled specially below
];

if ($page == 'reports.php') {
    if (!hasPermission('admin_access') && (($_SESSION['role'] ?? '') != 'technician')) {
        header("Location: index.php");
        exit;
    }
} elseif (isset($permission_pages[$page]) && !hasPermission($permission_pages[$page])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_setting('company_name', 'Repair CRM')); ?> - <?php echo e(__('dashboard')); ?></title>
    <!-- CSRF token for AJAX requests -->
    <meta name="csrf-token" content="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Bootstrap 5.3.3 CSS (Dark Theme fixes) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Fancybox 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css" />
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <!-- Custom Google Fonts (Inter) for Liquid Glass Theme -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- JQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="assets/js/main.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Fancybox 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.umd.js"></script>
    <script>
    // Automatically attach CSRF token to every jQuery AJAX POST request
    $(function() {
        var csrfToken = $('meta[name="csrf-token"]').attr('content');
        $.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if (settings.type === 'POST' || settings.type === 'post') {
                    if (typeof settings.data === 'string') {
                        settings.data += '&csrf_token=' + encodeURIComponent(csrfToken);
                    } else if (settings.data instanceof FormData) {
                        settings.data.append('csrf_token', csrfToken);
                    }
                }
            }
        });
    });
    </script>
    <script>
    window.LANG_NOTICE = '<?php echo __("notice_title"); ?>';
    window.LANG_CONFIRM = '<?php echo __("confirm_title"); ?>';
    window.LANG_PREVIEW = '<?php echo __("preview_btn"); ?>';
    </script>
</head>
<body>

<div id="sidebar">
    <div class="p-4 text-center">
        <h4><?php echo htmlspecialchars(get_setting('company_name', 'Repair CRM')); ?></h4>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home me-2"></i> <?php echo __('dashboard'); ?></a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'orders.php' ? 'active' : ''; ?>" href="orders.php"><i class="fas fa-tools me-2"></i> <?php echo __('orders'); ?></a>
        <?php if (hasPermission('edit_customers')): ?>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users me-2"></i> <?php echo __('customers'); ?></a>
        <?php endif; ?>
        <?php if (hasPermission('admin_access')): ?>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'inventory.php' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes me-2"></i> <?php echo __('inventory'); ?></a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-line me-2"></i> <?php echo __('reports'); ?></a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'accounting.php' ? 'active' : ''; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar me-2"></i> <?php echo __('accounting'); ?></a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas fa-cog me-2"></i> <?php echo __('settings'); ?></a>
        <?php else: ?>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-line me-2"></i> <?php echo __('reports'); ?></a>
            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas fa-user-circle me-2"></i> <?php echo __('settings'); ?></a>
        <?php endif; ?>
    </nav>
</div>

<div id="content">
    <nav class="navbar navbar-expand-lg navbar-dark mb-4 rounded shadow-sm">
        <div class="container-fluid d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <button class="btn btn-sm btn-outline-secondary me-3 d-lg-none" id="sidebarCollapse">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="navbar-brand mb-0 h1 d-none d-sm-inline-block"><?php echo get_setting('company_name', 'Repair CRM'); ?></span>
            </div>
            
            <!-- Smart Search -->
            <?php
            $current_page = basename($_SERVER['PHP_SELF']);
            $search_action = 'index.php';
            $search_placeholder = __('search_placeholder'); // Global search
            $show_search = true;
            
            if ($current_page == 'orders.php') {
                $search_action = 'orders.php';
                $search_placeholder = __('orders') . ' (ID, ' . __('client') . ', ' . __('device_model') . '...)';
            } elseif ($current_page == 'customers.php') {
                $search_action = 'customers.php';
                $search_placeholder = __('customers') . ' (ID, ' . __('client') . ', ' . __('phone') . ', ' . __('ico') . '...)';
            } elseif ($current_page == 'inventory.php') {
                $search_action = 'inventory.php';
                $search_placeholder = __('inventory') . ' (ID, ' . __('part_name') . ', ' . __('sku') . '...)';
            } elseif ($current_page == 'settings.php') {
                if ($_SESSION['role'] == 'admin') {
                    $search_action = 'settings.php';
                    $search_placeholder = __('technicians') . '...';
                } else {
                    $show_search = false; // Hide search for techs on profile page
                }
            }
            ?>
            <?php if ($show_search): ?>
            <form action="<?php echo $search_action; ?>" method="GET" class="d-flex mx-auto" style="max-width: 400px; width: 100%;">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
                    <button class="btn btn-outline-primary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
            <?php else: ?>
                <div class="mx-auto" style="max-width: 400px; width: 100%;"></div>
            <?php endif; ?>

            <div class="d-flex align-items-center">
                <span class="navbar-text me-3">
                    <i class="fas fa-user-circle me-1"></i> <?php echo e($_SESSION['full_name'] ?? __('technician')); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm"><?php echo __('logout'); ?></a>
            </div>
        </div>
    </nav>
