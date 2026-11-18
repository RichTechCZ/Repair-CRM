<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
// Page-level guard independent of header.php's permission map (defense in depth).
if (!hasPermission('edit_customers')) {
    header('Location: index.php');
    exit;
}
require_once 'includes/header.php';

$customers = [];
$total_customers = 0;
$total_pages = 1;
$page = 1;
$order_stats = [];
$last_orders = [];
$overview_stats = [
    'total_all' => 0,
    'total_private' => 0,
    'total_company' => 0,
    'total_active' => 0,
];

$search = normalizeSearchQuery((string)($_GET['search'] ?? ''));
$filter_type = in_array($_GET['type'] ?? '', ['private', 'company'], true) ? (string)$_GET['type'] : '';
$filter_activity = ($_GET['activity'] ?? '') === 'active' ? 'active' : '';
$sort = in_array($_GET['sort'] ?? '', ['name', 'recent', 'orders'], true) ? (string)$_GET['sort'] : 'name';
$is_admin_access = hasPermission('admin_access');
$tech_scoped = isTechnicianScoped();
$tech_id = currentTechnicianId();

$initial_customer_id = 0;
$deep_link_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($deep_link_id && isset($pdo) && currentUserCanViewCustomer((int)$deep_link_id)) {
    $initial_customer_id = (int)$deep_link_id;
}

$buildCustomersUrl = static function (array $overrides = []) use ($search, $filter_type, $filter_activity, $sort): string {
    $query = [
        'search' => $search !== '' ? $search : null,
        'type' => $filter_type !== '' ? $filter_type : null,
        'activity' => $filter_activity !== '' ? $filter_activity : null,
        'sort' => $sort !== 'name' ? $sort : null,
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    $query = array_filter($query, static fn($val) => $val !== null && $val !== '');
    $qs = http_build_query($query);
    return 'customers.php' . ($qs !== '' ? ('?' . $qs) : '');
};

if (isset($pdo)) {
    try {
        $limit = 50;
        $page = isset($_GET['cp']) && is_numeric($_GET['cp']) ? (int)$_GET['cp'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $activeStatusValues = getOrderStatusQueryValues(['Accepted', 'Diagnostics', 'Approval', 'In Repair', 'Ready']);
        $activePh = implode(',', array_fill(0, count($activeStatusValues), '?'));
        $issuedStatusValues = getOrderStatusQueryValues(['Issued']);
        $issuedPh = implode(',', array_fill(0, count($issuedStatusValues), '?'));

        $activeExistsSql = $tech_scoped
            ? "EXISTS (SELECT 1 FROM orders oa WHERE oa.customer_id = customers.id AND oa.technician_id = ? AND oa.status IN ($activePh))"
            : "EXISTS (SELECT 1 FROM orders oa WHERE oa.customer_id = customers.id AND oa.status IN ($activePh))";
        $activeExistsParams = $tech_scoped ? array_merge([$tech_id], $activeStatusValues) : $activeStatusValues;

        // Overview counts (one query, respecting technician scope)
        $overviewScopeSql = $tech_scoped
            ? 'WHERE EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id AND o.technician_id = ?)'
            : '';
        $overviewParams = $activeExistsParams;
        if ($tech_scoped) {
            $overviewParams[] = $tech_id;
        }
        $overviewStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_all,
                SUM(CASE WHEN customers.customer_type = 'company' THEN 0 ELSE 1 END) AS total_private,
                SUM(CASE WHEN customers.customer_type = 'company' THEN 1 ELSE 0 END) AS total_company,
                SUM(CASE WHEN {$activeExistsSql} THEN 1 ELSE 0 END) AS total_active
             FROM customers
             {$overviewScopeSql}"
        );
        $overviewStmt->execute($overviewParams);
        if ($ov = $overviewStmt->fetch(PDO::FETCH_ASSOC)) {
            $overview_stats = [
                'total_all' => (int)($ov['total_all'] ?? 0),
                'total_private' => (int)($ov['total_private'] ?? 0),
                'total_company' => (int)($ov['total_company'] ?? 0),
                'total_active' => (int)($ov['total_active'] ?? 0),
            ];
        }

        // Directory filters
        $where_parts = [];
        $params = [];

        if ($search !== '') {
            $escaped = addcslashes($search, "\\%_");
            $term = "%{$escaped}%";
            $phoneDigitsExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(customers.phone, ''), ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
            $search_clauses = [
                'customers.first_name LIKE ?',
                'customers.last_name LIKE ?',
                'customers.phone LIKE ?',
                'customers.email LIKE ?',
                'customers.ico LIKE ?',
                'customers.dic LIKE ?',
                'customers.company LIKE ?',
                "CONCAT_WS(' ', customers.first_name, customers.last_name) LIKE ?",
                "CONCAT_WS(' ', customers.last_name, customers.first_name) LIKE ?",
            ];
            $search_params = [$term, $term, $term, $term, $term, $term, $term, $term, $term];

            $digits = normalizePhoneForSearch($search);
            if (strlen($digits) >= 3) {
                $search_clauses[] = "{$phoneDigitsExpr} LIKE ?";
                $search_params[] = '%' . addcslashes($digits, "\\%_") . '%';
            }
            if (ctype_digit($search) && strlen($search) <= 10) {
                $search_clauses[] = 'customers.id = ?';
                $search_params[] = (int)$search;
            }
            $where_parts[] = '(' . implode(' OR ', $search_clauses) . ')';
            $params = array_merge($params, $search_params);
        }

        if ($filter_type === 'company') {
            $where_parts[] = "customers.customer_type = 'company'";
        } elseif ($filter_type === 'private') {
            $where_parts[] = "(customers.customer_type IS NULL OR customers.customer_type <> 'company')";
        }

        if ($filter_activity === 'active') {
            $where_parts[] = $activeExistsSql;
            $params = array_merge($params, $activeExistsParams);
        }

        if ($tech_scoped) {
            $where_parts[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id AND o.technician_id = ?)';
            $params[] = $tech_id;
        }

        $where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where_sql");
        $count_stmt->execute($params);
        $total_customers = (int)$count_stmt->fetchColumn();
        $total_pages = $total_customers > 0 ? (int)ceil($total_customers / $limit) : 1;
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $limit;

        $join_sql = '';
        $join_params = [];
        if ($sort === 'recent' || $sort === 'orders') {
            $aggWhere = $tech_scoped ? 'WHERE technician_id = ?' : '';
            if ($tech_scoped) {
                $join_params[] = $tech_id;
            }
            $join_sql = "LEFT JOIN (
                SELECT customer_id, COUNT(*) AS orders_cnt, MAX(created_at) AS last_order_at
                FROM orders
                {$aggWhere}
                GROUP BY customer_id
            ) ord_agg ON ord_agg.customer_id = customers.id";
        }

        $order_params = [];
        if ($sort === 'recent') {
            $order_sql = 'ORDER BY COALESCE(ord_agg.last_order_at, customers.created_at) DESC, customers.id DESC';
        } elseif ($sort === 'orders') {
            $order_sql = 'ORDER BY COALESCE(ord_agg.orders_cnt, 0) DESC, customers.last_name ASC, customers.id DESC';
        } elseif ($search !== '' && ctype_digit($search) && strlen($search) <= 10) {
            $order_sql = 'ORDER BY (CASE WHEN customers.id = ? THEN 1 ELSE 2 END), customers.last_name ASC, customers.first_name ASC, customers.id DESC';
            $order_params[] = (int)$search;
        } else {
            $order_sql = 'ORDER BY customers.last_name ASC, customers.first_name ASC, customers.id DESC';
        }

        $exec_params = array_merge($join_params, $params, $order_params);
        $stmt = $pdo->prepare(
            "SELECT customers.*
             FROM customers
             {$join_sql}
             {$where_sql}
             {$order_sql}
             LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($exec_params);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Batch-load repair metrics & latest repair for visible rows (zero N+1)
        if (!empty($customers)) {
            $customer_ids = array_values(array_map(static fn(array $c): int => (int)$c['id'], $customers));
            $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));

            $statsWhere = "o.customer_id IN ($placeholders)";
            $statsScopeParams = [];
            if ($tech_scoped) {
                $statsWhere .= ' AND o.technician_id = ?';
                $statsScopeParams[] = $tech_id;
            }

            if ($is_admin_access) {
                $statsSql =
                    "SELECT o.customer_id,
                            COUNT(*) AS total_cnt,
                            SUM(CASE WHEN o.status IN ($activePh) THEN 1 ELSE 0 END) AS active_cnt,
                            SUM(CASE WHEN o.status IN ($issuedPh)
                                THEN COALESCE(latest_invoice.total_amount, o.final_cost, o.estimated_cost, 0)
                                ELSE 0 END) AS total_spent
                     FROM orders o
                     LEFT JOIN (
                         SELECT order_id,
                                CAST(
                                    SUBSTRING_INDEX(
                                        GROUP_CONCAT(
                                            CAST(total_amount AS CHAR)
                                            ORDER BY COALESCE(payment_date, date_issue, created_at) DESC, id DESC
                                            SEPARATOR ','
                                        ),
                                        ',',
                                        1
                                    ) AS DECIMAL(15,2)
                                ) AS total_amount
                         FROM invoices
                         WHERE customer_id IN ($placeholders)
                           AND order_id IS NOT NULL
                           AND (invoice_type IS NULL OR invoice_type != 'credit_note')
                           AND status <> 'cancelled'
                         GROUP BY order_id
                     ) latest_invoice ON latest_invoice.order_id = o.id
                     WHERE {$statsWhere}
                     GROUP BY o.customer_id";
            } else {
                $statsSql =
                    "SELECT o.customer_id,
                            COUNT(*) AS total_cnt,
                            SUM(CASE WHEN o.status IN ($activePh) THEN 1 ELSE 0 END) AS active_cnt,
                            SUM(CASE WHEN o.status IN ($issuedPh)
                                THEN COALESCE(o.final_cost, o.estimated_cost, 0)
                                ELSE 0 END) AS total_spent
                     FROM orders o
                     WHERE {$statsWhere}
                     GROUP BY o.customer_id";
            }

            $c_stmt = $pdo->prepare($statsSql);
            $statsParams = $is_admin_access
                ? array_merge($activeStatusValues, $issuedStatusValues, $customer_ids, $customer_ids, $statsScopeParams)
                : array_merge($activeStatusValues, $issuedStatusValues, $customer_ids, $statsScopeParams);
            $c_stmt->execute($statsParams);
            while ($row = $c_stmt->fetch(PDO::FETCH_ASSOC)) {
                $order_stats[(int)$row['customer_id']] = [
                    'total' => (int)($row['total_cnt'] ?? 0),
                    'active' => (int)($row['active_cnt'] ?? 0),
                    'spent' => (float)($row['total_spent'] ?? 0.0),
                ];
            }

            $lastOrderScope = $tech_scoped ? ' AND o.technician_id = ?' : '';
            $lastSubScope = $tech_scoped ? ' AND o2.technician_id = ?' : '';
            $lastParams = $customer_ids;
            if ($tech_scoped) {
                $lastParams[] = $tech_id;
                $lastParams[] = $tech_id;
            }
            $lastStmt = $pdo->prepare(
                "SELECT o.id, o.customer_id, o.device_type, o.device_brand, o.device_model, o.status, o.created_at
                 FROM orders o
                 WHERE o.customer_id IN ($placeholders){$lastOrderScope}
                   AND NOT EXISTS (
                       SELECT 1 FROM orders o2
                       WHERE o2.customer_id = o.customer_id{$lastSubScope}
                         AND (o2.created_at > o.created_at OR (o2.created_at = o.created_at AND o2.id > o.id))
                   )"
            );
            $lastStmt->execute($lastParams);
            foreach ($lastStmt->fetchAll(PDO::FETCH_ASSOC) as $lo) {
                $cid = (int)$lo['customer_id'];
                if (!isset($last_orders[$cid])) {
                    $last_orders[$cid] = $lo;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('customers.php: ' . $e->getMessage());
    }
}

$hasActiveFilters = ($search !== '' || $filter_type !== '' || $filter_activity !== '' || $sort !== 'name');
?>

<section class="workspace-overview workspace-overview--customers ui-ready" aria-labelledby="customers-overview-title">
    <div class="workspace-overview__head">
        <div class="workspace-overview__copy">
            <h1 id="customers-overview-title"><?php echo e(__('customers_db')); ?></h1>
            <p class="workspace-overview__total">
                <?php echo e(__('all_customers')); ?>: <strong class="financial-number"><?php echo (int)$total_customers; ?></strong>
            </p>
        </div>
        <div class="page-actions">
            <?php if ($hasActiveFilters): ?>
                <a href="customers.php" class="btn btn-outline-secondary"><?php echo e(__('reset_search')); ?></a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCustomerModal">
                <i class="fas fa-user-plus" aria-hidden="true"></i>
                <span><?php echo e(__('add_customer')); ?></span>
            </button>
        </div>
    </div>

    <div class="workspace-overview__metrics workspace-overview__metrics--four" aria-label="<?php echo e(__('customers')); ?>">
        <a href="<?php echo e($buildCustomersUrl(['type' => null, 'activity' => null, 'cp' => null])); ?>"
           class="workspace-overview__metric <?php echo ($filter_type === '' && $filter_activity === '') ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo e(__('all_customers')); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo (int)$overview_stats['total_all']; ?></strong>
                <span class="workspace-overview__metric-note"><?php echo e(__('customers')); ?></span>
            </span>
        </a>
        <a href="<?php echo e($buildCustomersUrl(['type' => 'private', 'activity' => null, 'cp' => null])); ?>"
           class="workspace-overview__metric <?php echo $filter_type === 'private' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo e(__('private_persons')); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo (int)$overview_stats['total_private']; ?></strong>
                <span class="workspace-overview__metric-note"><?php echo e(__('private_person')); ?></span>
            </span>
        </a>
        <a href="<?php echo e($buildCustomersUrl(['type' => 'company', 'activity' => null, 'cp' => null])); ?>"
           class="workspace-overview__metric <?php echo $filter_type === 'company' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo e(__('companies')); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo (int)$overview_stats['total_company']; ?></strong>
                <span class="workspace-overview__metric-note"><?php echo e(__('company_marker')); ?></span>
            </span>
        </a>
        <a href="<?php echo e($buildCustomersUrl(['activity' => 'active', 'type' => null, 'cp' => null])); ?>"
           class="workspace-overview__metric <?php echo $filter_activity === 'active' ? 'is-active' : ''; ?>">
            <span class="workspace-overview__metric-label"><?php echo e(__('with_active_repairs')); ?></span>
            <span class="workspace-overview__metric-data">
                <strong class="workspace-overview__metric-value financial-number"><?php echo (int)$overview_stats['total_active']; ?></strong>
                <?php echo getStatusBadge('In Repair'); ?>
            </span>
        </a>
    </div>
</section>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success order-created-feedback" role="status"><?php echo e(__('customer_added_success')); ?></div>
<?php endif; ?>

<div class="card glass-card ui-ready">
    <div class="card-header customer-directory-toolbar">
        <div class="customer-directory-toolbar__meta">
            <?php if ($search !== ''): ?>
                <a class="customer-filter-chip" href="<?php echo e($buildCustomersUrl(['search' => null, 'cp' => null])); ?>">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <span><?php echo e($search); ?></span>
                    <i class="fas fa-times" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if ($filter_type !== ''): ?>
                <a class="customer-filter-chip" href="<?php echo e($buildCustomersUrl(['type' => null, 'cp' => null])); ?>">
                    <span><?php echo e($filter_type === 'company' ? __('companies') : __('private_persons')); ?></span>
                    <i class="fas fa-times" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if ($filter_activity === 'active'): ?>
                <a class="customer-filter-chip" href="<?php echo e($buildCustomersUrl(['activity' => null, 'cp' => null])); ?>">
                    <span><?php echo e(__('with_active_repairs')); ?></span>
                    <i class="fas fa-times" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if ($search === '' && $filter_type === '' && $filter_activity === ''): ?>
                <span class="small text-secondary"><?php echo e(__('customer_card')); ?> — <?php echo e(__('repairs_history')); ?></span>
            <?php endif; ?>
        </div>

        <nav class="customer-sort-group" aria-label="<?php echo e(__('sort_label')); ?>">
            <a href="<?php echo e($buildCustomersUrl(['sort' => 'name', 'cp' => null])); ?>"
               class="customer-sort-group__link <?php echo $sort === 'name' ? 'is-active' : ''; ?>"><?php echo e(__('sort_name')); ?></a>
            <a href="<?php echo e($buildCustomersUrl(['sort' => 'recent', 'cp' => null])); ?>"
               class="customer-sort-group__link <?php echo $sort === 'recent' ? 'is-active' : ''; ?>"><?php echo e(__('sort_recent')); ?></a>
            <a href="<?php echo e($buildCustomersUrl(['sort' => 'orders', 'cp' => null])); ?>"
               class="customer-sort-group__link <?php echo $sort === 'orders' ? 'is-active' : ''; ?>"><?php echo e(__('sort_orders')); ?></a>
        </nav>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive table-scroll-touch customers-table-wrap">
            <table class="table table-hover align-middle mb-0 table-mobile-cards customer-directory-table">
                <thead>
                    <tr>
                        <th class="ps-4"><?php echo e(__('client')); ?></th>
                        <th><?php echo e(__('phone')); ?> / <?php echo e(__('address')); ?></th>
                        <th><?php echo e(__('repairs_col')); ?></th>
                        <th><?php echo e(__('last_repair')); ?></th>
                        <th class="text-end"><?php echo e(__('amount')); ?></th>
                        <th class="text-end pe-4"><?php echo e(__('actions')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-state__mark" aria-hidden="true"></div>
                                    <p class="mb-0"><?php echo e(__('no_customers_found')); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <?php
                            $cid = (int)$customer['id'];
                            $isCompany = (($customer['customer_type'] ?? 'private') === 'company');
                            $displayName = crmCustomerDisplayName($customer);
                            $contactName = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
                            $words = preg_split('/\s+/u', trim($displayName)) ?: [];
                            if (count($words) >= 2) {
                                $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
                            } else {
                                $initials = mb_strtoupper(mb_substr($displayName, 0, 2));
                            }
                            if ($initials === '') {
                                $initials = $isCompany ? 'CO' : 'CL';
                            }

                            $phoneRaw = trim((string)($customer['phone'] ?? ''));
                            $phoneClean = preg_replace('/[^0-9+]/', '', $phoneRaw);
                            $emailRaw = trim((string)($customer['email'] ?? ''));
                            $addressRaw = trim((string)($customer['address'] ?? ''));

                            $stat = $order_stats[$cid] ?? ['total' => 0, 'active' => 0, 'spent' => 0.0];
                            $count = (int)$stat['total'];
                            $activeCount = (int)$stat['active'];
                            $totalSpent = (float)$stat['spent'];
                            $lastOrder = $last_orders[$cid] ?? null;
                        ?>
                        <tr class="customer-row" data-customer-id="<?php echo $cid; ?>">
                            <td class="ps-4" data-label="<?php echo e(__('client')); ?>">
                                <button type="button"
                                        class="customer-name-trigger"
                                        data-crm-action="open-customer-card"
                                        data-crm-id="<?php echo $cid; ?>"
                                        data-customer-name="<?php echo e($displayName); ?>">
                                    <span class="customer-avatar-chip <?php echo $isCompany ? 'customer-avatar-chip--company' : ''; ?>" aria-hidden="true"><?php echo e($initials); ?></span>
                                    <span class="customer-name-trigger__text"><?php echo e($displayName); ?></span>
                                </button>
                                <div class="customer-submeta">
                                    <span class="font-monospace">#<?php echo $cid; ?></span>
                                    <span class="customer-type-tag <?php echo $isCompany ? 'customer-type-tag--company' : ''; ?>">
                                        <?php echo e($isCompany ? __('company_entity') : __('private_person')); ?>
                                    </span>
                                    <?php if (!empty($customer['ico'])): ?>
                                        <span class="font-monospace">IČO: <?php echo e($customer['ico']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($isCompany && $contactName !== '' && $contactName !== $displayName): ?>
                                        <span><?php echo e($contactName); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td data-label="<?php echo e(__('phone')); ?>" data-crm-col="contacts">
                                <?php if ($phoneRaw !== ''): ?>
                                    <?php if ($phoneClean !== ''): ?>
                                    <div class="phone-qr-trigger small"
                                         data-phone="<?php echo e($phoneClean); ?>"
                                         role="button"
                                         tabindex="0"
                                         aria-expanded="false"
                                         aria-controls="phoneQrPopover"
                                         aria-haspopup="dialog"
                                         aria-label="<?php echo e($phoneRaw . ' — ' . __('phone_show_qr')); ?>">
                                        <i class="fas fa-phone text-success" aria-hidden="true"></i>
                                        <span><?php echo e($phoneRaw); ?></span>
                                        <i class="fas fa-qrcode phone-qr-trigger__hint" aria-hidden="true"></i>
                                    </div>
                                    <?php else: ?>
                                    <div class="small"><?php echo e($phoneRaw); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if ($emailRaw !== ''): ?>
                                    <div class="small text-white-75 text-truncate customer-contact-line">
                                        <i class="far fa-envelope me-1" aria-hidden="true"></i><?php echo e($emailRaw); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($addressRaw !== ''): ?>
                                    <div class="small text-tertiary text-truncate customer-contact-line" title="<?php echo e($addressRaw); ?>">
                                        <i class="fas fa-location-dot me-1" aria-hidden="true"></i><?php echo e($addressRaw); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($phoneRaw === '' && $emailRaw === '' && $addressRaw === ''): ?>
                                    <span class="text-tertiary">—</span>
                                <?php endif; ?>
                            </td>

                            <td data-label="<?php echo e(__('repairs_col')); ?>">
                                <button type="button"
                                        class="btn btn-sm <?php echo $count > 0 ? 'btn-outline-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3"
                                        data-customer-name="<?php echo e($displayName); ?>"
                                        data-crm-action="open-customer-card"
                                        data-crm-id="<?php echo $cid; ?>">
                                    <span class="financial-number"><?php echo $count; ?></span>
                                </button>
                                <?php if ($activeCount > 0): ?>
                                    <div class="mt-1">
                                        <span class="customer-active-indicator">
                                            <span class="customer-active-indicator__dot" aria-hidden="true"></span>
                                            <?php echo $activeCount; ?> <?php echo e(__('active_repairs_short')); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td data-label="<?php echo e(__('last_repair')); ?>">
                                <?php if ($lastOrder): ?>
                                    <div class="small fw-medium">
                                        <?php echo getDeviceIcon($lastOrder['device_type'] ?? 'Other'); ?>
                                        <span><?php echo e(trim(($lastOrder['device_brand'] ?? '') . ' ' . ($lastOrder['device_model'] ?? ''))); ?></span>
                                    </div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                                        <?php echo getStatusBadge($lastOrder['status']); ?>
                                        <span class="small text-tertiary font-monospace"><?php echo e(date('d.m.Y', strtotime($lastOrder['created_at']))); ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-tertiary">—</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end text-nowrap" data-label="<?php echo e(__('amount')); ?>">
                                <?php if ($totalSpent > 0): ?>
                                    <strong class="financial-number"><?php echo e(formatMoney($totalSpent)); ?></strong>
                                <?php else: ?>
                                    <span class="text-tertiary">—</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end pe-4 mobile-row-actions" data-label="">
                                <div class="btn-group btn-group-sm customer-row-actions">
                                    <button type="button"
                                            class="btn btn-outline-primary"
                                            data-crm-action="open-customer-card"
                                            data-crm-id="<?php echo $cid; ?>"
                                            data-customer-name="<?php echo e($displayName); ?>"
                                            title="<?php echo e(__('open_card')); ?>"
                                            aria-label="<?php echo e(__('open_card') . ': ' . $displayName); ?>">
                                        <i class="fas fa-id-card" aria-hidden="true"></i>
                                        <span class="d-none d-xxl-inline ms-1"><?php echo e(__('open_btn')); ?></span>
                                    </button>
                                    <a href="orders.php?new_order=1&customer_id=<?php echo $cid; ?>"
                                       class="btn btn-outline-secondary"
                                       title="<?php echo e(__('new_order')); ?>"
                                       aria-label="<?php echo e(__('new_order') . ': ' . $displayName); ?>">
                                        <i class="fas fa-plus" aria-hidden="true"></i>
                                    </a>
                                    <button type="button"
                                            class="btn btn-outline-secondary"
                                            data-crm-action="open-customer-card"
                                            data-crm-id="<?php echo $cid; ?>"
                                            data-crm-tab="edit"
                                            data-customer-name="<?php echo e($displayName); ?>"
                                            title="<?php echo e(__('edit')); ?>"
                                            aria-label="<?php echo e(__('edit') . ': ' . $displayName); ?>">
                                        <i class="fas fa-edit" aria-hidden="true"></i>
                                    </button>
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
<?php if (isset($total_pages) && $total_pages > 1): ?>
<nav class="mt-4" aria-label="Pagination">
    <ul class="pagination justify-content-center">
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="<?php echo e($buildCustomersUrl(['cp' => max(1, $page - 1)])); ?>" aria-label="<?php echo e(__('back')); ?>">
                <i class="fas fa-chevron-left" aria-hidden="true"></i>
            </a>
        </li>
        <?php
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        if ($start > 1) {
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="' . e($buildCustomersUrl(['cp' => 1])) . '">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }
        for ($i = $start; $i <= $end; $i++):
        ?>
            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="<?php echo e($buildCustomersUrl(['cp' => $i])); ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor;
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="' . e($buildCustomersUrl(['cp' => $total_pages])) . '">' . $total_pages . '</a></li>';
        }
        ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="<?php echo e($buildCustomersUrl(['cp' => min($total_pages, $page + 1)])); ?>" aria-label="Next">
                <i class="fas fa-chevron-right" aria-hidden="true"></i>
            </a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<!-- Customer Card Slide-over Modal -->
<div class="modal fade customer-card-modal" id="customerCardModal" tabindex="-1" aria-labelledby="customerCardModalTitle">
    <div class="modal-dialog modal-xl customer-card-modal__dialog">
        <div class="modal-content customer-card-modal__content">
            <div class="modal-header customer-card__header">
                <div class="customer-card__identity">
                    <div class="customer-card__avatar" id="customerCardAvatar" aria-hidden="true">CL</div>
                    <div class="customer-card__identity-copy">
                        <h5 class="modal-title" id="customerCardModalTitle"><?php echo e(__('customer_card')); ?></h5>
                        <div class="customer-card__subtitle" id="customerCardSubtitle"></div>
                    </div>
                </div>
                <div class="customer-card__header-actions">
                    <a href="#" id="customerCardNewOrderBtn" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus" aria-hidden="true"></i>
                        <span><?php echo e(__('new_order')); ?></span>
                    </a>
                    <button type="button" id="customerCardEditToggleBtn" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-edit" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline"><?php echo e(__('edit')); ?></span>
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
                </div>
            </div>

            <div class="modal-body customer-card__body">
                <div id="customerCardLoading" class="text-center py-5 d-none">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden"><?php echo e(__('loading_text')); ?></span>
                    </div>
                    <p class="mt-2 mb-0 small text-secondary"><?php echo e(__('loading_text')); ?></p>
                </div>

                <div id="customerCardError" class="alert alert-danger d-none" role="alert"></div>

                <div id="customerCardContent" class="d-none">
                    <div class="customer-card__overview">
                        <section class="customer-card__profile" id="ccProfilePanel" aria-label="<?php echo e(__('customer_contacts')); ?>"></section>
                        <section class="customer-card__kpis" id="ccKpisGrid" aria-label="<?php echo e(__('repairs_history')); ?>"></section>
                    </div>

                    <ul class="nav nav-pills customer-card__tabs" id="customerCardTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="ccTabRepairs" data-bs-toggle="tab" data-bs-target="#ccPaneRepairs" type="button" role="tab" aria-controls="ccPaneRepairs" aria-selected="true">
                                <i class="fas fa-screwdriver-wrench" aria-hidden="true"></i>
                                <span><?php echo e(__('repairs_history')); ?></span>
                                <span class="customer-card__tab-count" id="ccTabRepairsCount">0</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ccTabDevices" data-bs-toggle="tab" data-bs-target="#ccPaneDevices" type="button" role="tab" aria-controls="ccPaneDevices" aria-selected="false">
                                <i class="fas fa-mobile-screen" aria-hidden="true"></i>
                                <span><?php echo e(__('customer_devices')); ?></span>
                                <span class="customer-card__tab-count" id="ccTabDevicesCount">0</span>
                            </button>
                        </li>
                        <?php if ($is_admin_access): ?>
                        <li class="nav-item" id="ccTabInvoicesItem" role="presentation">
                            <button class="nav-link" id="ccTabInvoices" data-bs-toggle="tab" data-bs-target="#ccPaneInvoices" type="button" role="tab" aria-controls="ccPaneInvoices" aria-selected="false">
                                <i class="fas fa-file-invoice" aria-hidden="true"></i>
                                <span><?php echo e(__('invoices_list')); ?></span>
                                <span class="customer-card__tab-count" id="ccTabInvoicesCount">0</span>
                            </button>
                        </li>
                        <?php endif; ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ccTabEdit" data-bs-toggle="tab" data-bs-target="#ccPaneEdit" type="button" role="tab" aria-controls="ccPaneEdit" aria-selected="false">
                                <i class="fas fa-user-pen" aria-hidden="true"></i>
                                <span><?php echo e(__('edit')); ?></span>
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="ccPaneRepairs" role="tabpanel" aria-labelledby="ccTabRepairs">
                            <div class="customer-repairs-toolbar">
                                <div class="customer-filter-pills" id="ccRepairFilters" role="group" aria-label="<?php echo e(__('filters')); ?>">
                                    <button type="button" class="customer-filter-pill is-active" data-filter="all">
                                        <span><?php echo e(__('filter_all')); ?></span>
                                        <span class="customer-filter-pill__count">0</span>
                                    </button>
                                    <button type="button" class="customer-filter-pill" data-filter="active">
                                        <span><?php echo e(__('filter_active')); ?></span>
                                        <span class="customer-filter-pill__count">0</span>
                                    </button>
                                    <button type="button" class="customer-filter-pill" data-filter="completed">
                                        <span><?php echo e(__('filter_completed')); ?></span>
                                        <span class="customer-filter-pill__count">0</span>
                                    </button>
                                    <button type="button" class="customer-filter-pill" data-filter="cancelled">
                                        <span><?php echo e(__('filter_cancelled')); ?></span>
                                        <span class="customer-filter-pill__count">0</span>
                                    </button>
                                </div>
                                <label for="ccRepairSearchInput" class="visually-hidden"><?php echo e(__('search_customer_repairs_placeholder')); ?></label>
                                <input type="search"
                                       id="ccRepairSearchInput"
                                       class="form-control customer-repairs-search"
                                       placeholder="<?php echo e(__('search_customer_repairs_placeholder')); ?>">
                            </div>
                            <div class="customer-repairs-list" id="ccRepairsList"></div>
                        </div>

                        <div class="tab-pane fade" id="ccPaneDevices" role="tabpanel" aria-labelledby="ccTabDevices">
                            <div id="ccDevicesList"></div>
                        </div>

                        <?php if ($is_admin_access): ?>
                        <div class="tab-pane fade" id="ccPaneInvoices" role="tabpanel" aria-labelledby="ccTabInvoices">
                            <div id="ccInvoicesList"></div>
                        </div>
                        <?php endif; ?>

                        <div class="tab-pane fade" id="ccPaneEdit" role="tabpanel" aria-labelledby="ccTabEdit">
                            <form id="customerCardEditForm" class="customer-edit-panel">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="id" id="ccEditId" value="">
                                <div id="ccEditError" class="alert alert-danger d-none mb-3" role="alert"></div>

                                <div class="mb-3">
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="customer_type" id="ccEditTypePrivate" value="private" checked>
                                        <label class="btn btn-outline-primary" for="ccEditTypePrivate"><?php echo e(__('private_person')); ?></label>
                                        <input type="radio" class="btn-check" name="customer_type" id="ccEditTypeCompany" value="company">
                                        <label class="btn btn-outline-primary" for="ccEditTypeCompany"><?php echo e(__('company_entity')); ?></label>
                                    </div>
                                </div>

                                <div id="ccEditCompanyFields" class="d-none border p-3 rounded bg-dark bg-opacity-25 border-secondary mb-3">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label" for="ccEditIco"><?php echo e(__('ico')); ?></label>
                                            <div class="input-group">
                                                <input type="text" name="ico" id="ccEditIco" class="form-control" maxlength="20">
                                                <button class="btn btn-outline-secondary" type="button" id="ccEditFetchAresBtn" aria-label="<?php echo e(__('fetch_ares')); ?>">
                                                    <i class="fas fa-search" aria-hidden="true"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="ccEditDic"><?php echo e(__('dic')); ?></label>
                                            <input type="text" name="dic" id="ccEditDic" class="form-control" maxlength="20">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label" for="ccEditCompany"><?php echo e(__('company_name')); ?></label>
                                            <input type="text" name="company" id="ccEditCompany" class="form-control" maxlength="100">
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="ccEditFirstName"><?php echo e(__('client')); ?> (<?php echo e(__('client_first_name')); ?>)</label>
                                        <input type="text" name="first_name" id="ccEditFirstName" class="form-control" maxlength="50" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="ccEditLastName"><?php echo e(__('client')); ?> (<?php echo e(__('client_last_name')); ?>)</label>
                                        <input type="text" name="last_name" id="ccEditLastName" class="form-control" maxlength="50" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="ccEditPhone"><?php echo e(__('phone')); ?></label>
                                        <input type="tel" name="phone" id="ccEditPhone" class="form-control" maxlength="20" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="ccEditEmail">Email</label>
                                        <input type="email" name="email" id="ccEditEmail" class="form-control" maxlength="100">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="ccEditAddress"><?php echo e(__('address')); ?></label>
                                        <textarea name="address" id="ccEditAddress" class="form-control" rows="2" maxlength="1000"></textarea>
                                    </div>
                                    <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2">
                                        <div class="d-flex flex-wrap gap-2">
                                            <button type="submit" class="btn btn-primary" id="ccEditSaveBtn">
                                                <i class="fas fa-check me-1" aria-hidden="true"></i><?php echo e(__('save_changes')); ?>
                                            </button>
                                            <a href="#" id="ccFullEditLink" class="btn btn-outline-secondary">
                                                <?php echo e(__('full_edit_page')); ?>
                                            </a>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-1">
                                            <button type="button" class="btn btn-outline-danger btn-sm" id="ccEditDeleteBtn">
                                                <i class="fas fa-trash me-1" aria-hidden="true"></i><?php echo e(__('delete')); ?>
                                            </button>
                                            <span id="ccEditDeleteHint" class="small text-tertiary d-none"><?php echo e(__('delete_customer_has_orders_hint')); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Language Selection for Reception Act Modal -->
<div class="modal fade" id="receptionLangModal" tabindex="-1" aria-labelledby="receptionLangModalTitle">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark bg-opacity-25 border-secondary border-0">
                <h6 class="modal-title fw-bold" id="receptionLangModalTitle"><?php echo e(__('select_print_language')); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="langOrderId">
                <div class="d-grid gap-3">
                    <button type="button" class="btn btn-outline-primary py-3 btn-lang-select" data-lang="ru">
                        <span class="lang-code me-2" aria-hidden="true">RU</span> <?php echo e(__('lang_ru')); ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary py-3 btn-lang-select" data-lang="cs">
                        <span class="lang-code me-2" aria-hidden="true">CZ</span> <?php echo e(__('lang_cs')); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Customer Modal -->
<div class="modal fade" id="newCustomerModal" tabindex="-1" aria-labelledby="newCustomerModalTitle">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="api/add_customer.php" method="POST" id="newCustomerForm" data-crm-once>
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="newCustomerModalTitle"><?php echo e(__('add_customer')); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="customer_type" id="type_private" value="private" checked>
                            <label class="btn btn-outline-primary" for="type_private"><?php echo e(__('private_person')); ?></label>

                            <input type="radio" class="btn-check" name="customer_type" id="type_company" value="company">
                            <label class="btn btn-outline-primary" for="type_company"><?php echo e(__('company_entity')); ?></label>
                        </div>
                    </div>

                    <div id="company_fields" class="d-none border p-3 rounded bg-dark bg-opacity-25 border-secondary mb-3">
                        <div class="mb-3">
                            <label class="form-label"><?php echo e(__('ico')); ?></label>
                            <div class="input-group">
                                <input type="text" name="ico" id="ico_input" class="form-control" placeholder="12345678" maxlength="20">
                                <button class="btn btn-outline-secondary" type="button" id="btn_fetch_ares">
                                    <i class="fas fa-search me-1" aria-hidden="true"></i> <?php echo e(__('fetch_ares')); ?>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo e(__('company_name')); ?></label>
                            <input type="text" name="company_name" id="ares_name" class="form-control" maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo e(__('dic')); ?></label>
                            <input type="text" name="dic" id="ares_dic" class="form-control" placeholder="CZ12345678" maxlength="20">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e(__('client')); ?> (<?php echo e(__('name_col')); ?>)</label>
                            <input type="text" name="first_name" id="ares_first_name" class="form-control" maxlength="50" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo e(__('client')); ?> (<?php echo e(__('last_name_label')); ?>)</label>
                            <input type="text" name="last_name" id="ares_last_name" class="form-control" maxlength="50" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo e(__('phone')); ?></label>
                            <input type="tel" name="phone" class="form-control" maxlength="20" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" maxlength="100">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo e(__('address')); ?></label>
                            <textarea name="address" id="ares_address" class="form-control" rows="2" maxlength="1000"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo e(__('cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo e(__('save')); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Phone QR Popover Container -->
<div class="qr-popover" id="phoneQrPopover" role="dialog" aria-modal="false" aria-hidden="true" aria-label="<?php echo e(__('phone_show_qr')); ?>">
    <div class="qr-phone-label" id="qrPhoneLabel"></div>
    <div id="qrContainer"></div>
    <a href="#" class="btn btn-success qr-call-btn" id="qrCallBtn">
        <i class="fas fa-phone" aria-hidden="true"></i><?php echo e(__('call')); ?>
    </a>
</div>

<!-- Non-blocking Feedback Toast -->
<div class="toast-container crm-toast-container position-fixed bottom-0 end-0 p-3">
    <div id="customersToast" class="toast crm-toast crm-toast--success align-items-center" role="status" aria-live="polite" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="customersToastBody"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="<?php echo e(__('close')); ?>"></button>
        </div>
    </div>
</div>

<?php
$crmJsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$customersConfig = [
    'currency' => (string)get_setting('currency', 'Kč'),
    'csrfToken' => (string)($_SESSION['csrf_token'] ?? ''),
    'initialCustomerId' => $initial_customer_id,
    'initialTab' => in_array($_GET['tab'] ?? '', ['repairs', 'devices', 'invoices', 'edit'], true) ? (string)$_GET['tab'] : 'repairs',
    'i18n' => [
        'customer_card' => __('customer_card'),
        'customer_contacts' => __('customer_contacts'),
        'repairs_history' => __('repairs_history'),
        'phone' => __('phone'),
        'address' => __('address'),
        'company' => __('company'),
        'contact_person' => __('contact_person'),
        'ico' => __('ico'),
        'dic' => __('dic'),
        'private_person' => __('private_person'),
        'company_entity' => __('company_entity'),
        'customer_since' => __('customer_since'),
        'not_specified' => __('not_specified'),
        'copy_text' => __('copy_text'),
        'copied' => __('copied'),
        'phone_show_qr' => __('phone_show_qr'),
        'total_repairs' => __('total_repairs'),
        'active_repairs' => __('active_repairs'),
        'unique_devices' => __('unique_devices'),
        'total_spent' => __('total_spent'),
        'average_check' => __('average_check'),
        'last_repair' => __('last_repair'),
        'status_ready' => __('status_ready'),
        'repair_summary_issued' => __('repair_summary_issued'),
        'repair_summary_cancelled' => __('repair_summary_cancelled'),
        'estimated_short' => __('estimated_short'),
        'final_short' => __('final_short'),
        'reclamation' => __('reclamation'),
        'high' => __('high'),
        'notes' => __('notes'),
        'cancellation_reason' => __('cancellation_reason'),
        'sn1' => __('sn1'),
        'sn2' => __('sn2'),
        'parts_used' => __('parts_used'),
        'media_files' => __('media_files'),
        'amount' => __('amount'),
        'open_btn' => __('open_btn'),
        'new_order' => __('new_order'),
        'print' => __('print'),
        'a4_invoice' => __('a4_invoice'),
        'reception_act_thermal' => __('reception_act_thermal'),
        'work_order' => __('work_order'),
        'thermal_receipt' => __('thermal_receipt'),
        'invoice' => __('invoice'),
        'invoice_number' => __('invoice_number'),
        'date_issue' => __('date_issue'),
        'order' => __('order'),
        'status' => __('status'),
        'action' => __('action'),
        'preview_btn' => __('preview_btn'),
        'no_invoices' => __('no_invoices'),
        'no_devices_found' => __('no_devices_found'),
        'visits_count' => __('visits_count'),
        'show_device_repairs' => __('show_device_repairs'),
        'customer_no_repairs_hint' => __('customer_no_repairs_hint'),
        'no_matching_repairs' => __('no_matching_repairs'),
        'clear_filter' => __('clear_filter'),
        'loading_text' => __('loading_text'),
        'error_loading_data' => __('error_loading_data'),
        'enter_ico_prompt' => __('enter_ico_prompt'),
        'ares_data_not_found' => __('ares_data_not_found'),
        'ares_fetch_error' => __('ares_fetch_error'),
        'saving' => __('saving'),
        'customer_updated_success' => __('customer_updated_success'),
        'confirm_delete_customer' => __('confirm_delete_customer'),
        'error' => __('error'),
    ],
];
?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
window.CUSTOMERS_CONFIG = <?php echo json_encode($customersConfig, $crmJsFlags); ?>;
</script>
<script nonce="<?php echo e(crmCspNonce()); ?>" src="assets/js/customers.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/customers.js'); ?>"></script>

<?php require_once 'includes/footer.php'; ?>
