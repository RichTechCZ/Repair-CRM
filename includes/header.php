<?php
require_once __DIR__ . '/functions.php';

// Ensure CSP helpers exist even when an older config.php is still on the host.
if (!function_exists('crmCspNonce')) {
    $crmCspFile = __DIR__ . '/content_security_policy.php';
    if (is_file($crmCspFile)) {
        require_once $crmCspFile;
    }
}
if (function_exists('crmStartContentSecurityPolicy')) {
    crmStartContentSecurityPolicy();
}
if (!function_exists('crmCspNonce')) {
    function crmCspNonce(): string
    {
        return '';
    }
}

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
} elseif ($page == 'statistics.php') {
    if (!hasPermission('admin_access') && !isTechnicianScoped()) {
        header("Location: index.php");
        exit;
    }
} elseif (isset($permission_pages[$page]) && !hasPermission($permission_pages[$page])) {
    header("Location: index.php");
    exit;
}
?>
<?php

// Resolve page title early so <title> is contextual (Trunk Test / browser tabs).
$company_name = (string)get_setting('company_name', 'Repair CRM');
$current_page = basename($_SERVER['PHP_SELF']);
$page_titles = [
    'index.php' => __('dashboard'),
    'orders.php' => __('orders'),
    'customers.php' => __('customers'),
    'inventory.php' => __('inventory'),
    'reports.php' => __('reports'),
    'statistics.php' => __('statistics'),
    'accounting.php' => __('accounting'),
    'settings.php' => __('settings'),
    'view_order.php' => __('order'),
    'edit_order.php' => __('edit'),
    'edit_customer.php' => __('customers'),
    'edit_inventory.php' => __('inventory'),
    'login.php' => __('login'),
];
$page_title = $page_titles[$current_page] ?? $company_name;

?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['lang'] ?? 'ru'); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f1115">
    <meta name="color-scheme" content="dark">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo e($company_name); ?>">
    <title><?php echo e($company_name); ?> - <?php echo e($page_title); ?></title>
    <!-- CSRF token for AJAX requests -->
    <meta name="csrf-token" content="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
    <!-- Integrity-pinned third-party dependencies -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <!-- Bootstrap 5.3.3 CSS (Dark Theme fixes) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Fancybox 5.0.36 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0.36/dist/fancybox/fancybox.css"
          integrity="sha384-qlUhevqmCF5AxtnfkF0zXJClBzA6GJuX/UrLejCfE61bBGt+zo/My0AJ+ojVmUSb"
          crossorigin="anonymous">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"
          integrity="sha384-OXVF05DQEe311p6ohU11NwlnX08FzMCsyoXzGOaL+83dKAb3qS17yZJxESl8YrJQ"
          crossorigin="anonymous">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/css/style.css'); ?>">

    <!-- Phone / iPhone layer -->
    <link rel="stylesheet" href="assets/css/mobile.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/mobile.css'); ?>" media="(max-width: 991.98px)">

    <!-- JQuery -->
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="https://code.jquery.com/jquery-3.6.0.min.js"
            integrity="sha384-vtXRMe3mGCbOeY7l30aIg8H9p3GdeSe4IFlP6G8JMa7o7lXvnz3GFKzPxzJdPfGK"
            crossorigin="anonymous"></script>
    <!-- Bootstrap 5 JS Bundle -->
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
            crossorigin="anonymous"></script>
    <!-- Custom JS (filemtime busts browser cache after deploys) -->
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="assets/js/mobile.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/mobile.js'); ?>" defer></script>
    <!-- Select2 JS -->
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"
            integrity="sha384-d3UHjPdzJkZuk5H3qKYMLRyWLAQBJbby2yr2Q58hXXtAGF8RSNO9jpLDlKKPv5v3"
            crossorigin="anonymous"></script>
    <!-- Fancybox 5.0.36 JS -->
    <script nonce="<?php echo e(crmCspNonce()); ?>" src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0.36/dist/fancybox/fancybox.umd.js"
            integrity="sha384-BodKYo5iRmFaqEaP1o8AAu9hCHqLvNhSWEg12QF1IjPnl1SgsrwQMSMKUB4POJ18"
            crossorigin="anonymous"></script>
    <script nonce="<?php echo e(crmCspNonce()); ?>">
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
                // Replace missing or empty tokens; empty values still "has()" and would skip CSRF.
                var existingFormToken = data.has('csrf_token') ? String(data.get('csrf_token') || '') : '';
                if (existingFormToken === '') {
                    if (data.has('csrf_token')) {
                        data.delete('csrf_token');
                    }
                    data.append('csrf_token', csrfToken);
                }
                options.data = data;
                options.processData = false;
                options.contentType = false;
                return;
            }

            if (data && typeof data === 'object') {
                var payload = $.extend(true, {}, data);
                if (!payload.csrf_token) {
                    payload.csrf_token = csrfToken;
                }
                options.data = $.param(payload);
                options.processData = true;
                return;
            }

            if (typeof data === 'string') {
                if (!/(^|&)csrf_token=/.test(data) || /(?:^|&)csrf_token=(?:&|$)/.test(data)) {
                    // Missing token, or present but empty (csrf_token=).
                    var withoutEmpty = data.replace(/(^|&)csrf_token=(?:&|$)/g, function(match, sep) {
                        return sep === '&' ? '&' : '';
                    }).replace(/&$/g, '');
                    options.data = withoutEmpty
                        ? (withoutEmpty + '&csrf_token=' + encodeURIComponent(csrfToken))
                        : ('csrf_token=' + encodeURIComponent(csrfToken));
                }
                return;
            }

            options.data = 'csrf_token=' + encodeURIComponent(csrfToken);
        });
    });
    </script>
    <script nonce="<?php echo e(crmCspNonce()); ?>">
    window.LANG_NOTICE = '<?php echo __("notice_title"); ?>';
    window.LANG_CONFIRM = '<?php echo __("confirm_title"); ?>';
    window.LANG_PREVIEW = '<?php echo __("preview_btn"); ?>';
    window.LANG_CLOSE = '<?php echo __("close"); ?>';
    window.LANG_OPEN_NAVIGATION = '<?php echo __("open_navigation"); ?>';
    window.LANG_CLOSE_NAVIGATION = '<?php echo __("close_navigation"); ?>';
    </script>
</head>
<body class="app-page app-page--<?php echo e(preg_replace('/[^a-z0-9_-]/i', '', pathinfo(basename($_SERVER['PHP_SELF']), PATHINFO_FILENAME))); ?>">
<?php
$company_name = (string)get_setting('company_name', 'Repair CRM');
$current_page = basename($_SERVER['PHP_SELF']);
$page_titles = [
    'index.php' => __('dashboard'),
    'orders.php' => __('orders'),
    'customers.php' => __('customers'),
    'inventory.php' => __('inventory'),
    'reports.php' => __('reports'),
    'statistics.php' => __('statistics'),
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

// Dashboard topbar and Orders page search use the same order-search contract:
// same target fields, same GET key, same engine (searchOrdersList).
$order_search_placeholder = __('orders') . ' (#ID, ' . __('client') . ', ' . __('phone') . ', ' . __('device_model') . ', ' . __('serial') . '...)';

if ($current_page == 'index.php' || $current_page == 'orders.php') {
    $search_action = ($current_page == 'orders.php') ? 'orders.php' : 'index.php';
    $search_placeholder = $order_search_placeholder;
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

// Orders keeps its search next to the page context and primary action.
$show_topbar_search = $show_search && $current_page !== 'orders.php';

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
        'href' => 'statistics.php',
        'label' => __('statistics'),
        'icon' => 'reports',
        'visible' => hasPermission('admin_access') || isTechnicianScoped(),
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

<aside id="sidebar" class="app-sidebar" aria-label="Primary navigation">
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
            <a class="nav-link <?php echo $is_active ? 'active' : ''; ?>" href="<?php echo e($item['href']); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?>>
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
    <nav class="navbar navbar-expand-lg topbar-shell <?php echo $show_topbar_search ? '' : 'topbar-shell--searchless'; ?> mb-4" aria-label="Header">
        <div class="container-fluid topbar-main">
            <div class="topbar-navigation">
                <button class="btn btn-outline-secondary topbar-menu d-lg-none" id="sidebarCollapse" type="button" aria-label="<?php echo e(__('open_navigation')); ?>" aria-controls="sidebar" aria-expanded="false">
                    <span class="menu-bars" aria-hidden="true"></span>
                </button>
            </div>

            <?php if ($show_topbar_search): ?>
            <form action="<?php echo e($search_action); ?>" method="GET" class="topbar-search" role="search">
                <label for="globalSearch" class="visually-hidden"><?php echo e(__('search_placeholder')); ?></label>
                <div class="search-shell">
                    <span class="search-shell__icon" aria-hidden="true"></span>
                    <input id="globalSearch" type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
                    <button class="btn btn-primary btn-sm px-3" type="submit">Go</button>
                </div>
            </form>
            <?php elseif (!$show_search): ?>
                <div class="topbar-search topbar-search--empty" aria-hidden="true"></div>
            <?php endif; ?>

            <div class="topbar-actions">
                <div class="topbar-user-chip">
                    <span class="topbar-user-chip__avatar" aria-hidden="true"><?php echo e($user_initial); ?></span>
                <div class="topbar-user-chip__copy">
                    <strong><?php echo e($_SESSION['full_name'] ?? __('technician')); ?></strong>
                </div>
            </div>
            </div>
        </div>
    </nav>

    <main id="main-content" class="content-shell" tabindex="-1">
