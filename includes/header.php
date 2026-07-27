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
    'edit_inventory.php' => 'admin_access',
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
<html lang="<?php echo e($_SESSION['lang'] ?? 'ru'); ?>" data-bs-theme="dark">
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

    <!-- Typography: refined UI font + mono accents for metrics -->
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
        $.ajaxPrefilter(function(options, originalOptions) {
            var method = String(options.type || options.method || 'GET').toUpperCase();
            var data = originalOptions.data !== undefined ? originalOptions.data : options.data;

            if (method !== 'POST' || !csrfToken) {
                return;
            }

            if (data instanceof FormData) {
                if (!data.has('csrf_token')) {
                    data.append('csrf_token', csrfToken);
                }
                options.data = data;
                options.processData = false;
                options.contentType = false;
                return;
            }

            if (data && typeof data === 'object') {
                var payload = $.extend(true, {}, data);
                if (!Object.prototype.hasOwnProperty.call(payload, 'csrf_token')) {
                    payload.csrf_token = csrfToken;
                }
                options.data = $.param(payload);
                options.processData = true;
                return;
            }

            if (typeof data === 'string') {
                if (!/(^|&)csrf_token=/.test(data)) {
                    options.data = data ? (data + '&csrf_token=' + encodeURIComponent(csrfToken)) : ('csrf_token=' + encodeURIComponent(csrfToken));
                }
                return;
            }

            options.data = 'csrf_token=' + encodeURIComponent(csrfToken);
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
<?php
$company_name = (string)get_setting('company_name', 'Repair CRM');
$current_page = basename($_SERVER['PHP_SELF']);
$page_titles = [
    'index.php' => __('dashboard'),
    'orders.php' => __('orders'),
    'customers.php' => __('customers'),
    'inventory.php' => __('inventory'),
    'reports.php' => __('reports'),
    'accounting.php' => __('accounting'),
    'settings.php' => __('settings'),
    'view_order.php' => __('order'),
    'edit_order.php' => __('edit'),
    'edit_customer.php' => __('customers'),
    'edit_inventory.php' => __('inventory'),
];
$page_title = $page_titles[$current_page] ?? $company_name;

$search_action = 'index.php';
$search_placeholder = __('search_placeholder');
$show_search = true;

if ($current_page == 'orders.php') {
    $search_action = 'orders.php';
    $search_placeholder = __('orders') . ' (#ID, ' . __('client') . ', ' . __('phone') . ', ' . __('device_model') . ', ' . __('serial') . '...)';
} elseif ($current_page == 'customers.php') {
    $search_action = 'customers.php';
    $search_placeholder = __('customers') . ' (ID, ' . __('client') . ', ' . __('phone') . ', ' . __('ico') . '...)';
} elseif ($current_page == 'inventory.php') {
    $search_action = 'inventory.php';
    $search_placeholder = __('inventory') . ' (ID, ' . __('part_name') . ', ' . __('sku') . '...)';
} elseif ($current_page == 'settings.php') {
    if (($_SESSION['role'] ?? '') == 'admin') {
        $search_action = 'settings.php';
        $search_placeholder = __('technicians') . '...';
    } else {
        $show_search = false;
    }
}

$nav_items = [
    [
        'href' => 'index.php',
        'label' => __('dashboard'),
        'icon' => 'dashboard',
        'visible' => true,
    ],
    [
        'href' => 'orders.php',
        'label' => __('orders'),
        'icon' => 'orders',
        'visible' => true,
    ],
    [
        'href' => 'customers.php',
        'label' => __('customers'),
        'icon' => 'customers',
        'visible' => hasPermission('edit_customers'),
    ],
    [
        'href' => 'inventory.php',
        'label' => __('inventory'),
        'icon' => 'inventory',
        'visible' => hasPermission('admin_access'),
    ],
    [
        'href' => 'reports.php',
        'label' => __('reports'),
        'icon' => 'reports',
        'visible' => hasPermission('admin_access') || (($_SESSION['role'] ?? '') === 'technician'),
    ],
    [
        'href' => 'accounting.php',
        'label' => __('accounting'),
        'icon' => 'accounting',
        'visible' => hasPermission('admin_access'),
    ],
    [
        'href' => 'settings.php',
        'label' => __('settings'),
        'icon' => 'settings',
        'visible' => true,
    ],
];

$user_initial = mb_strtoupper(mb_substr((string)($_SESSION['full_name'] ?? 'U'), 0, 1));
?>

<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<aside id="sidebar" class="app-sidebar" aria-label="Primary">
    <div class="sidebar-brand">
        <div class="brand-mark" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
        </div>
        <div class="brand-copy">
            <strong><?php echo e($company_name); ?></strong>
            <span>Service operations</span>
        </div>
    </div>

    <div class="sidebar-section-label">Workspace</div>
    <nav class="nav flex-column app-nav">
        <?php foreach ($nav_items as $item): ?>
            <?php if (!$item['visible']) continue; ?>
            <?php $is_active = $current_page === basename($item['href']); ?>
            <a class="nav-link <?php echo $is_active ? 'active' : ''; ?>" href="<?php echo e($item['href']); ?>">
                <span class="nav-mark nav-mark--<?php echo e($item['icon']); ?>" aria-hidden="true"></span>
                <span><?php echo e($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
        <div class="sidebar-user">
            <span class="sidebar-user__avatar" aria-hidden="true"><?php echo e($user_initial); ?></span>
            <div class="sidebar-user__copy">
                <strong><?php echo e($_SESSION['full_name'] ?? __('technician')); ?></strong>
                <span><?php echo e((($_SESSION['role'] ?? '') === 'admin') ? 'Admin access' : __('technician')); ?></span>
            </div>
        </div>
        <a href="logout.php" class="btn btn-outline-secondary btn-sm w-100"><?php echo __('logout'); ?></a>
    </div>
</aside>

<div id="content" class="app-content">
    <nav class="navbar navbar-expand-lg topbar-shell mb-4" aria-label="Header">
        <div class="container-fluid topbar-main">
            <div class="topbar-title-group">
                <button class="btn btn-outline-secondary topbar-menu d-lg-none" id="sidebarCollapse" type="button" aria-label="Open navigation">
                    <span class="menu-bars" aria-hidden="true"></span>
                </button>
                <div>
                    <div class="page-kicker"><?php echo e($company_name); ?></div>
                    <span class="navbar-brand page-title mb-0"><?php echo e($page_title); ?></span>
                </div>
            </div>

            <?php if ($show_search): ?>
            <form action="<?php echo e($search_action); ?>" method="GET" class="topbar-search" role="search">
                <label for="globalSearch" class="visually-hidden"><?php echo e(__('search_placeholder')); ?></label>
                <div class="search-shell">
                    <span class="search-shell__icon" aria-hidden="true"></span>
                    <input id="globalSearch" type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
                    <button class="btn btn-primary btn-sm px-3" type="submit">Go</button>
                </div>
            </form>
            <?php else: ?>
                <div class="topbar-search topbar-search--empty" aria-hidden="true"></div>
            <?php endif; ?>

            <div class="topbar-actions">
                <div class="topbar-user-chip">
                    <span class="topbar-user-chip__avatar" aria-hidden="true"><?php echo e($user_initial); ?></span>
                    <div class="topbar-user-chip__copy">
                        <strong><?php echo e($_SESSION['full_name'] ?? __('technician')); ?></strong>
                        <span><?php echo e($page_title); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main id="main-content" class="content-shell" tabindex="-1">
