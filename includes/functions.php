<?php
/**
 * Helper Functions for CRM
 */

/**
 * Check if current user has a specific permission.
 * All permissions are loaded from the DB once per session and cached in $_SESSION['_perms'].
 * Call invalidatePermissionsCache() whenever permissions or the session is updated.
 */
function hasPermission($permission) {
    global $pdo;

    // Admins always have all permissions
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }

    // Technicians/Staff – use session-level cache
    if (($_SESSION['role'] ?? '') === 'technician' && isset($_SESSION['tech_id'])) {
        // Re-read permissions at most once a minute so revoked rights do not outlive the session.
        if (!isset($_SESSION['_perms']) || (time() - (int)($_SESSION['_perms_at'] ?? 0)) > 60) {
            $stmt = $pdo->prepare('SELECT permission FROM tech_permissions WHERE technician_id = ?');
            $stmt->execute([$_SESSION['tech_id']]);
            $raw = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $allowed = array_fill_keys(getAllowedPermissionKeys(), true);
            $_SESSION['_perms'] = array_values(array_filter($raw, static function ($p) use ($allowed) {
                return isset($allowed[$p]);
            }));
            $_SESSION['_perms_at'] = time();
        }

        // admin_access grants everything
        if (in_array('admin_access', $_SESSION['_perms'], true)) {
            return true;
        }

        return in_array($permission, $_SESSION['_perms'], true);
    }

    return false;
}

function currentUserCanViewOrder($order_id): bool {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (hasPermission('admin_access')) {
        return true;
    }

    if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['tech_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT technician_id FROM orders WHERE id = ?');
    $stmt->execute([(int)$order_id]);
    $technician_id = $stmt->fetchColumn();

    return $technician_id !== false && (int)$technician_id === (int)$_SESSION['tech_id'];
}

function currentUserCanEditOrder($order_id): bool {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (hasPermission('admin_access')) {
        return true;
    }

    if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['tech_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT technician_id FROM orders WHERE id = ?');
    $stmt->execute([(int)$order_id]);
    $technician_id = $stmt->fetchColumn();

    return $technician_id !== false && (int)$technician_id === (int)$_SESSION['tech_id'];
}

function currentUserCanViewCustomer($customer_id): bool {
    global $pdo;

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    if (hasPermission('admin_access')) {
        return true;
    }

    if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['tech_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE customer_id = ? AND technician_id = ? LIMIT 1');
    $stmt->execute([(int)$customer_id, (int)$_SESSION['tech_id']]);

    return (bool)$stmt->fetchColumn();
}

function grantCustomerForOrderCreation(int $customerId): void
{
    if (isTechnicianScoped() && $customerId > 0) {
        $_SESSION['_new_customer_order_grants'][(string)$customerId] = time();
    }
}

function currentUserCanCreateOrderForCustomer(int $customerId): bool
{
    if ($customerId <= 0 || empty($_SESSION['user_id'])) {
        return false;
    }
    if (hasPermission('admin_access')) {
        return true;
    }
    if (!isTechnicianScoped()) {
        return false;
    }
    if (currentUserCanViewCustomer($customerId)) {
        return true;
    }

    $now = time();
    $grants = is_array($_SESSION['_new_customer_order_grants'] ?? null)
        ? $_SESSION['_new_customer_order_grants']
        : [];
    foreach ($grants as $grantedCustomerId => $grantedAt) {
        if (($now - (int)$grantedAt) > 15 * 60) {
            unset($grants[$grantedCustomerId]);
        }
    }
    $_SESSION['_new_customer_order_grants'] = $grants;

    return isset($grants[(string)$customerId]);
}

function consumeCustomerOrderCreationGrant(int $customerId): void
{
    unset($_SESSION['_new_customer_order_grants'][(string)$customerId]);
}

/**
 * True when the current user must only see data tied to their own technician_id.
 * Users with admin_access are not scoped.
 */
function isTechnicianScoped(): bool {
    if (hasPermission('admin_access')) {
        return false;
    }
    return ($_SESSION['role'] ?? '') === 'technician' && !empty($_SESSION['tech_id']);
}

/**
 * Current session technician id, or null.
 */
function currentTechnicianId(): ?int {
    if (empty($_SESSION['tech_id'])) {
        return null;
    }
    return (int)$_SESSION['tech_id'];
}

/**
 * Client-safe exception message. Logs DB/system errors; passes intentional business messages through.
 */
function publicExceptionMessage(Throwable $e): string {
    if ($e instanceof PDOException) {
        error_log('PDO: ' . $e->getMessage());
        return sprintf(__('db_error'), '');
    }

    $msg = trim((string)$e->getMessage());
    if ($msg === '') {
        error_log(get_class($e) . ': empty message');
        return __('error');
    }

    // Hide internals (SQL, stack traces, filesystem paths)
    if (preg_match(
        '/SQLSTATE|Stack trace| on line \d+|Failed opening|Permission denied|[A-Za-z]:\\\\|\/(?:var|home|usr|tmp|etc)\//i',
        $msg
    )) {
        error_log(get_class($e) . ': ' . $msg);
        return __('error');
    }

    return $msg;
}

/**
 * Invalidate the in-session permissions cache.
 * Call after setTechPermissions() or on logout.
 */
function invalidatePermissionsCache(): void {
    unset($_SESSION['_perms'], $_SESSION['_perms_at']);
}

/**
 * Return all active technicians.
 * Result is statically cached for the lifetime of the current PHP request.
 */
function getActiveTechnicians(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = $pdo->query(
            'SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}


/**
 * Get all permissions for a technician
 */
function getTechPermissions($tech_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT permission FROM tech_permissions WHERE technician_id = ?");
    $stmt->execute([$tech_id]);
    $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Drop obsolete keys so the UI never looks like they still apply
    $allowed = array_fill_keys(getAllowedPermissionKeys(), true);
    return array_values(array_filter($perms, static function ($p) use ($allowed) {
        return isset($allowed[$p]);
    }));
}

/**
 * Set permissions for a technician (replaces all existing).
 * Only keys from getAvailablePermissions() are stored (whitelist).
 */
function setTechPermissions($tech_id, $permissions) {
    global $pdo;

    $tech_id = (int)$tech_id;
    $allowed = array_fill_keys(getAllowedPermissionKeys(), true);
    $clean = [];
    foreach ((array)$permissions as $perm) {
        $perm = (string)$perm;
        if (isset($allowed[$perm])) {
            $clean[$perm] = true;
        }
    }
    $clean = array_keys($clean);

    // Delete existing
    $stmt = $pdo->prepare("DELETE FROM tech_permissions WHERE technician_id = ?");
    $stmt->execute([$tech_id]);

    // Insert new
    if (!empty($clean)) {
        $stmt = $pdo->prepare("INSERT INTO tech_permissions (technician_id, permission) VALUES (?, ?)");
        foreach ($clean as $perm) {
            $stmt->execute([$tech_id, $perm]);
        }
    }

    // Drop any obsolete permission rows still stored for other techs
    purgeObsoleteTechPermissions();

    // Invalidate session permission cache so changes take effect immediately
    invalidatePermissionsCache();
}

/**
 * Active permission keys that can be assigned in Settings → Staff.
 *
 * Binding matrix (DOX):
 * - session role `admin` (users table) → full access (hasPermission always true)
 * - `admin_access` → full CRM access; not technician-scoped
 * - no special order permission → every technician can view/edit ONLY their own
 *   orders (technician_id match). Cross-tech order access is never grantable.
 * - `edit_customers` → customers UI/API; technicians remain scoped to customers
 *   they share an order with
 * - `manage_passwords` → change admin account passwords in settings
 *
 * Removed (never enforced, contradicted isolation): view_all_orders, edit_orders
 */
function getAvailablePermissions() {
    return [
        'admin_access' => ['name' => __('perm_admin_access'), 'desc' => __('perm_admin_access_desc'), 'icon' => 'fas fa-crown text-warning'],
        'edit_customers' => ['name' => __('perm_edit_customers'), 'desc' => __('perm_edit_customers_desc'), 'icon' => 'fas fa-user-edit text-success'],
        'manage_passwords' => ['name' => __('perm_manage_passwords'), 'desc' => __('perm_manage_passwords_desc'), 'icon' => 'fas fa-key text-danger'],
    ];
}

/**
 * @return list<string>
 */
function getAllowedPermissionKeys(): array {
    return array_keys(getAvailablePermissions());
}

/**
 * Delete tech_permissions rows that are no longer assignable.
 */
function purgeObsoleteTechPermissions(): void {
    global $pdo;
    if (!isset($pdo)) {
        return;
    }

    $allowed = getAllowedPermissionKeys();
    try {
        if (empty($allowed)) {
            $pdo->exec('DELETE FROM tech_permissions');
            return;
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '?'));
        $stmt = $pdo->prepare("DELETE FROM tech_permissions WHERE permission NOT IN ($placeholders)");
        $stmt->execute($allowed);
    } catch (Throwable $e) {
        error_log('purgeObsoleteTechPermissions: ' . $e->getMessage());
    }
}

function getDeviceIcon($type) {
    $icons = [
        'Phone' => 'fa-mobile-alt',
        'Notebook' => 'fa-laptop',
        'PC' => 'fa-desktop',
        'Computer' => 'fa-desktop',
        'Tablet' => 'fa-tablet-alt',
        'HDD' => 'fa-hdd',
    ];

    $icon = $icons[$type] ?? 'fa-tools';
    return '<i class="fas ' . $icon . ' device-row-icon" aria-hidden="true"></i>';
}

function getStatusBadge($status) {
    $variants = [
        'Accepted' => 'accepted',
        'New' => 'accepted',
        'Diagnostics' => 'diagnostics',
        'Approval' => 'approval',
        'Pending Approval' => 'approval',
        'In Repair' => 'repair',
        'In Progress' => 'repair',
        'Waiting for Parts' => 'waiting',
        'Ready' => 'ready',
        'Completed' => 'ready',
        'Issued' => 'issued',
        'Collected' => 'issued',
        'Issued Without Repair' => 'closed',
        'Repair Cancelled' => 'cancelled',
        'Cancelled' => 'cancelled',
    ];

    $variant = $variants[$status] ?? 'closed';
    return '<span class="status-pill status-pill--' . $variant . '">' . htmlspecialchars(getStatusLabel($status)) . '</span>';
}

/**
 * Localized tonal status pill for invoice lifecycle (draft / issued / paid / overdue / cancelled).
 */
function getInvoiceStatusBadge($status) {
    $variants = [
        'draft' => 'draft',
        'issued' => 'inv-issued',
        'paid' => 'paid',
        'overdue' => 'overdue',
        'cancelled' => 'cancelled',
    ];
    $labels = [
        'draft' => __('status_draft'),
        'issued' => __('status_invoice_issued'),
        'paid' => __('status_paid'),
        'overdue' => __('status_overdue'),
        'cancelled' => __('status_cancelled'),
    ];
    $key = (string)$status;
    $variant = $variants[$key] ?? 'closed';
    $label = $labels[$key] ?? $key;
    return '<span class="status-pill status-pill--' . htmlspecialchars($variant) . '">' . htmlspecialchars($label) . '</span>';
}

function getAllStatuses(): array {
    return [
        'Accepted',
        'Diagnostics',
        'Approval',
        'In Repair',
        'Ready',
        'Issued',
        'Issued Without Repair',
        'Repair Cancelled',
    ];
}

function getLegacyStatusMap(): array {
    return [
        'New' => 'Accepted',
        'Pending Approval' => 'Approval',
        'In Progress' => 'In Repair',
        'Waiting for Parts' => 'In Repair',
        'Completed' => 'Ready',
        'Collected' => 'Issued',
        'Cancelled' => 'Repair Cancelled',
    ];
}

function canonicalOrderStatus($status): string {
    $status = (string)$status;
    $legacy_map = getLegacyStatusMap();
    return $legacy_map[$status] ?? $status;
}

function getLegacyEquivalentStatus(string $status): ?string {
    $reverse_map = [
        'Accepted' => 'New',
        'Diagnostics' => 'In Progress',
        'Approval' => 'Pending Approval',
        'In Repair' => 'In Progress',
        'Ready' => 'Completed',
        'Issued' => 'Collected',
        'Issued Without Repair' => 'Cancelled',
        'Repair Cancelled' => 'Cancelled',
    ];

    return $reverse_map[$status] ?? null;
}

function getOrderStatusStorageValues(): array {
    global $pdo;
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $type = $column['Type'] ?? '';
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $matches);
        $cache = array_map(static function ($value) {
            return str_replace("\\'", "'", $value);
        }, $matches[1] ?? []);
    } catch (Exception $e) {
        $cache = [];
    }

    return $cache;
}

function orderStatusValueSupported(string $status): bool {
    $values = getOrderStatusStorageValues();
    return empty($values) || in_array($status, $values, true);
}

function getOrderStatusStorageValue(string $status): string {
    if (orderStatusValueSupported($status)) {
        return $status;
    }

    $canonical = canonicalOrderStatus($status);
    if ($canonical !== $status && orderStatusValueSupported($canonical)) {
        return $canonical;
    }

    $legacy = getLegacyEquivalentStatus($canonical);
    if ($legacy !== null && orderStatusValueSupported($legacy)) {
        return $legacy;
    }

    return $status;
}

function getDefaultOrderStatus(): string {
    return getOrderStatusStorageValue('Accepted');
}

function getOrderStatusQueryValues(array $statuses): array {
    $values = [];
    foreach ($statuses as $status) {
        $status = (string)$status;
        $values[] = $status;
        $canonical = canonicalOrderStatus($status);
        $values[] = $canonical;
        $legacy = getLegacyEquivalentStatus($canonical);
        if ($legacy !== null) {
            $values[] = $legacy;
        }
        foreach (getLegacyStatusMap() as $legacy_status => $canonical_status) {
            if ($canonical_status === $canonical) {
                $values[] = $legacy_status;
            }
        }
    }

    return array_values(array_unique(array_filter($values, static function ($value) {
        return $value !== '';
    })));
}

function buildStatusInCondition(string $column, array $statuses, array &$params): string {
    $values = getOrderStatusQueryValues($statuses);
    $params = array_merge($params, $values);
    return $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
}

function getDashboardStatusGroups(): array {
    return [
        'new' => ['Accepted'],
        'pending' => ['Approval'],
        'progress' => ['Diagnostics', 'In Repair'],
        'ready' => ['Ready', 'Issued'],
    ];
}

function countOrdersByStatusGroup(array $statuses, ?int $technicianId = null): int {
    global $pdo;

    $params = [];
    $where = buildStatusInCondition('status', $statuses, $params);

    if ($technicianId !== null) {
        $where .= ' AND technician_id = ?';
        $params[] = $technicianId;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ' . $where);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function tableColumnExists(string $table, string $column): bool {
    global $pdo;
    static $cache = [];
    $key = $table . '.' . $column;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function getStatusLabel($status): string {
    $labels = [
        'Accepted' => __('status_accepted'),
        'Diagnostics' => __('status_diagnostics'),
        'Approval' => __('status_approval'),
        'In Repair' => __('status_in_repair'),
        'Ready' => __('status_ready'),
        'Issued' => __('status_issued'),
        'Issued Without Repair' => __('status_issued_without_repair'),
        'Repair Cancelled' => __('status_repair_cancelled'),
        // Legacy labels keep old records and logs readable before migration.
        'New' => __('new'),
        'Pending Approval' => __('pending_approval'),
        'In Progress' => __('in_progress'),
        'Waiting for Parts' => __('waiting_parts'),
        'Completed' => __('completed'),
        'Collected' => __('collected'),
        'Cancelled' => __('cancelled'),
    ];

    return $labels[$status] ?? (string)$status;
}

function getDeviceModels($brand = null, string $term = '', int $limit = 50): array {
    global $pdo;

    $limit = max(1, min($limit, 100));
    $sql = 'SELECT model_name, usage_count FROM device_models WHERE 1=1';
    $params = [];

    if ($brand !== null && trim((string)$brand) !== '') {
        $sql .= ' AND UPPER(brand) = UPPER(?)';
        $params[] = trim((string)$brand);
    }

    if ($term !== '') {
        $sql .= ' AND model_name LIKE ?';
        $params[] = '%' . $term . '%';
    }

    $sql .= ' ORDER BY usage_count DESC, model_name ASC LIMIT ' . $limit;

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function saveDeviceModelUsage(string $brand, string $model): void {
    global $pdo;

    $brand = trim($brand);
    $model = trim($model);
    if ($brand === '' || $model === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO device_models (brand, model_name, usage_count)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE usage_count = usage_count + 1"
        );
        $stmt->execute([$brand, $model]);
    } catch (Exception $e) {
        // Autocomplete history must not block order creation.
    }
}

function formatMoney($amount) {
    global $pdo;
    $currency = get_setting('currency', 'Kč');
    return number_format($amount, 2, '.', ' ') . ' ' . $currency;
}

function normalizeSearchQuery(string $search): string {
    $normalized = preg_replace('/\s+/u', ' ', trim($search));
    return is_string($normalized) ? $normalized : trim($search);
}

function normalizePhoneForSearch(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    return is_string($digits) ? substr($digits, 0, 32) : '';
}

/**
 * Parse the date formats shown in the Orders UI into an index-friendly range.
 *
 * Supported formats are YYYY-MM-DD, DD.MM.YYYY (also '/' and '-'),
 * YYYY-MM, MM.YYYY, and a four-digit year.
 */
function parseOrderSearchDateRange(string $token): ?array
{
    $token = trim($token);
    $year = $month = $day = null;
    $precision = null;

    if (preg_match('/^(\d{4})[-.]?(\d{2})[-.]?(\d{2})$/u', $token, $matches)) {
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        $precision = 'day';
    } elseif (preg_match('/^(\d{2})[-.\/]?(\d{2})[-.\/](\d{4})$/u', $token, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];
        $precision = 'day';
    } elseif (preg_match('/^(\d{4})-(\d{2})$/u', $token, $matches)) {
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $precision = 'month';
    } elseif (preg_match('/^(\d{2})[-.\/](\d{4})$/u', $token, $matches)) {
        $month = (int)$matches[1];
        $year = (int)$matches[2];
        $precision = 'month';
    } elseif (preg_match('/^(\d{4})$/u', $token, $matches)) {
        $year = (int)$matches[1];
        $month = 1;
        $day = 1;
        $precision = 'year';
    }

    if ($year === null || $month === null || ($precision === 'day' && $day === null)) {
        return null;
    }
    if ($precision === 'day' && !checkdate((int)$month, (int)$day, (int)$year)) {
        return null;
    }
    if ($precision === 'month' && ($year < 1000 || $year > 9999 || $month < 1 || $month > 12)) {
        return null;
    }
    if ($precision === 'year' && ($year < 1000 || $year > 9999)) {
        return null;
    }

    $start = new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day ?? 1));
    $end = $precision === 'year'
        ? $start->modify('+1 year')
        : ($precision === 'month' ? $start->modify('+1 month') : $start->modify('+1 day'));

    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'precision' => $precision,
    ];
}

function buildOrderSearchQueryParts(
    string $search,
    string $orderAlias = 'o',
    string $customerAlias = 'c',
    string $techAlias = 't',
    bool $useIndexedSearch = true
): array {
    foreach ([$orderAlias, $customerAlias, $techAlias] as $alias) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias)) {
            throw new InvalidArgumentException('Invalid search table alias.');
        }
    }

    $search = normalizeSearchQuery($search);
    $parts = [
        'search' => $search,
        'where_clauses' => [],
        'where_params' => [],
        'score_sql' => '0',
        'score_params' => [],
        'exact_id' => 0,
        'uses_optional_indexes' => false,
    ];
    if ($search === '') {
        return $parts;
    }

    // An explicit # prefix unambiguously means an order ID. Bare numeric
    // searches must continue through the serial fields because IMEI/IMEI2
    // values are commonly digits only.
    if (preg_match('/^\s*#\s*(\d+)\s*$/u', $search, $matches)) {
        $parts['exact_id'] = (int)$matches[1];
        $parts['where_clauses'][] = "{$orderAlias}.id = ?";
        $parts['where_params'][] = $parts['exact_id'];
        $parts['score_sql'] = '1400';
        return $parts;
    }

    if (preg_match('/^\s*(\d{1,10})\s*$/u', $search, $matches)) {
        $parts['exact_id'] = (int)$matches[1];
    }

    $rawTokens = preg_split('/[\s,;]+/u', $search) ?: [];
    $tokens = [];
    foreach ($rawTokens as $rawToken) {
        $token = preg_replace('/[^\p{L}\p{N}@._+\/\-]+/u', '', trim($rawToken));
        if (is_string($token) && $token !== '') {
            $tokens[$token] = true;
        }
    }
    $tokens = array_slice(array_keys($tokens), 0, 6);
    if ($tokens === []) {
        return $parts;
    }

    $fullNameExpr = "TRIM(CONCAT_WS(' ', COALESCE({$customerAlias}.first_name, ''), COALESCE({$customerAlias}.last_name, '')))";
    $reverseNameExpr = "TRIM(CONCAT_WS(' ', COALESCE({$customerAlias}.last_name, ''), COALESCE({$customerAlias}.first_name, '')))";
    $brandModelExpr = "TRIM(CONCAT_WS(' ', COALESCE({$orderAlias}.device_brand, ''), COALESCE({$orderAlias}.device_model, '')))";
    $companyExpr = "COALESCE({$customerAlias}.company, '')";
    $emailExpr = "COALESCE({$customerAlias}.email, '')";
    $phoneExpr = "COALESCE({$customerAlias}.phone, '')";
    $phoneDigitsExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$phoneExpr}, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', ''), ',', '')";
    $techNameExpr = "COALESCE({$techAlias}.name, '')";
    $orderMatch = "MATCH({$orderAlias}.device_brand, {$orderAlias}.device_model, {$orderAlias}.problem_description, {$orderAlias}.serial_number, {$orderAlias}.serial_number_2)";
    $customerMatch = "MATCH({$customerAlias}.first_name, {$customerAlias}.last_name, {$customerAlias}.company, {$customerAlias}.phone)";
    $technicianMatch = "MATCH({$techAlias}.name)";
    $searchDigits = normalizePhoneForSearch($search);
    $scoreParts = [];

    if ($parts['exact_id'] > 0) {
        $scoreParts[] = "CASE WHEN {$orderAlias}.id = ? THEN 1400 ELSE 0 END";
        $parts['score_params'][] = $parts['exact_id'];
    }

    foreach ($tokens as $tokenIndex => $token) {
        $escapedToken = addcslashes($token, "\\%_");
        // Serial/IMEI values are identifiers, not words: users often paste
        // a middle fragment or a value containing separators/spaces.
        $serialLike = '%' . $escapedToken . '%';
        $textLike = $serialLike;
        $digitToken = preg_replace('/\D+/', '', $token);
        $booleanWord = preg_replace('/[^\p{L}\p{N}]+/u', '', $token);
        $dateRange = parseOrderSearchDateRange($token);
        // Identifier-shaped queries (IMEI/S/N fragments) must work even when
        // an older production database has not yet built optional full-text
        // or phone-search indexes. The direct serial LIKE clauses below use
        // only the baseline orders columns.
        $isIdentifierToken = is_string($digitToken) && strlen($digitToken) >= 4;
        $isPhoneLikeToken = is_string($digitToken)
            && strlen($digitToken) >= 4
            && strlen($digitToken) <= 14
            && $dateRange === null
            && preg_match('/^[+\d().\/ -]+$/u', $token) === 1;
        $tokenClauses = [
            "{$orderAlias}.serial_number LIKE ?",
            "{$orderAlias}.serial_number_2 LIKE ?",
            "{$orderAlias}.device_brand LIKE ?",
            "{$orderAlias}.device_model LIKE ?",
            "{$customerAlias}.first_name LIKE ?",
            "{$customerAlias}.last_name LIKE ?",
            "{$customerAlias}.company LIKE ?",
            "{$customerAlias}.email LIKE ?",
            "{$customerAlias}.phone LIKE ?",
            "{$phoneDigitsExpr} LIKE ?",
            "{$orderAlias}.problem_description LIKE ?",
            "{$orderAlias}.status LIKE ?",
            "{$techAlias}.name LIKE ?",
        ];
        array_push(
            $parts['where_params'],
            $serialLike,
            $serialLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike,
            $textLike
        );

        if (preg_match('/^\d{1,10}$/u', $token)) {
            $tokenClauses[] = "{$orderAlias}.id = ?";
            $parts['where_params'][] = (int)$token;
        }

        if (is_string($digitToken) && $digitToken !== '') {
            $tokenClauses[] = "{$phoneDigitsExpr} LIKE ?";
            $parts['where_params'][] = '%' . addcslashes($digitToken, "\\%_") . '%';
        }

        if ($dateRange !== null) {
            $tokenClauses[] = "({$orderAlias}.created_at >= ? AND {$orderAlias}.created_at < ?)";
            $parts['where_params'][] = $dateRange['start'];
            $parts['where_params'][] = $dateRange['end'];
        }

        // A multi-word name, company, or device phrase is a useful boost while
        // the per-token clauses below keep partial and mixed-field searches
        // working (for example: "Apple iPhone 13").
        if ($tokenIndex === 0 && count($tokens) > 1) {
            $phraseLike = '%' . addcslashes(implode(' ', $tokens), "\\%_") . '%';
            $tokenClauses[] = "{$fullNameExpr} LIKE ?";
            $tokenClauses[] = "{$reverseNameExpr} LIKE ?";
            $tokenClauses[] = "{$brandModelExpr} LIKE ?";
            $tokenClauses[] = "{$companyExpr} LIKE ?";
            $tokenClauses[] = "{$emailExpr} LIKE ?";
            array_push($parts['where_params'], $phraseLike, $phraseLike, $phraseLike, $phraseLike, $phraseLike);
        }

        // Use the normalized expression as the reliable path for every
        // formatted or partially entered phone number. The optional indexed
        // column is only an acceleration path and never the sole match path.
        if ($tokenIndex === 0 && strlen($searchDigits) >= 4) {
            $tokenClauses[] = "{$phoneDigitsExpr} LIKE ?";
            $parts['where_params'][] = '%' . addcslashes($searchDigits, "\\%_") . '%';
        }

        $scoreParts[] = "CASE WHEN {$orderAlias}.serial_number LIKE ? OR {$orderAlias}.serial_number_2 LIKE ? THEN 600 ELSE 0 END";
        array_push($parts['score_params'], $serialLike, $serialLike);

        $booleanWordLength = is_string($booleanWord)
            ? (function_exists('mb_strlen') ? mb_strlen($booleanWord) : strlen($booleanWord))
            : 0;
        if ($useIndexedSearch && !$isIdentifierToken && $booleanWordLength >= 3) {
            $booleanToken = '+' . $booleanWord . '*';
            array_push(
                $tokenClauses,
                "{$orderMatch} AGAINST (? IN BOOLEAN MODE)",
                "{$customerMatch} AGAINST (? IN BOOLEAN MODE)",
                "{$technicianMatch} AGAINST (? IN BOOLEAN MODE)"
            );
            array_push($parts['where_params'], $booleanToken, $booleanToken, $booleanToken);
            $scoreParts[] = "({$orderMatch} AGAINST (? IN BOOLEAN MODE) * 8)";
            $scoreParts[] = "({$customerMatch} AGAINST (? IN BOOLEAN MODE) * 6)";
            $scoreParts[] = "({$technicianMatch} AGAINST (? IN BOOLEAN MODE) * 3)";
            array_push($parts['score_params'], $booleanToken, $booleanToken, $booleanToken);
            $parts['uses_optional_indexes'] = true;
        }

        if ($useIndexedSearch && $isPhoneLikeToken) {
            $tokenClauses[] = "{$customerAlias}.phone_search LIKE ?";
            $parts['where_params'][] = $digitToken . '%';
            $scoreParts[] = "CASE WHEN {$customerAlias}.phone_search LIKE ? THEN 300 ELSE 0 END";
            $parts['score_params'][] = $digitToken . '%';
            $parts['uses_optional_indexes'] = true;
        }

        $parts['where_clauses'][] = '(' . implode(' OR ', $tokenClauses) . ')';
    }

    if (strlen($searchDigits) >= 4) {
        $scoreParts[] = "CASE WHEN {$phoneDigitsExpr} LIKE ? THEN 520 ELSE 0 END";
        $parts['score_params'][] = '%' . addcslashes($searchDigits, "\\%_") . '%';
    }

    if (count($tokens) > 1) {
        $phraseLike = '%' . addcslashes(implode(' ', $tokens), "\\%_") . '%';
        $scoreParts[] = "CASE WHEN {$fullNameExpr} LIKE ? OR {$reverseNameExpr} LIKE ? THEN 420 ELSE 0 END";
        $scoreParts[] = "CASE WHEN {$brandModelExpr} LIKE ? OR {$companyExpr} LIKE ? OR {$emailExpr} LIKE ? THEN 320 ELSE 0 END";
        array_push($parts['score_params'], $phraseLike, $phraseLike, $phraseLike, $phraseLike, $phraseLike);
    }

    $parts['score_sql'] = $scoreParts !== [] ? '(' . implode(' + ', $scoreParts) . ')' : '1';
    return $parts;
}

/**
 * Shared order list search used by Dashboard topbar and Orders page search.
 *
 * FULLTEXT / phone_search are optional accelerators. When production schema
 * lacks those indexes, the same request is retried on the portable LIKE/date
 * path so both search shells return identical results.
 *
 * @return array{orders: array<int, array<string, mixed>>, total: int, search: string, search_parts: array<string, mixed>}
 */
function searchOrdersList(
    PDO $pdo,
    string $search,
    ?int $technicianId = null,
    ?string $filterStatus = null,
    int $limit = 50,
    int $offset = 0,
    bool $includeTotal = true
): array {
    $search = normalizeSearchQuery($search);
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $dashboard_status_groups = getDashboardStatusGroups();
    $canonical_filter_status = canonicalOrderStatus($filterStatus ?? '');

    $search_parts = buildOrderSearchQueryParts($search, 'o', 'c', 't');
    $search_candidates = [$search_parts];
    if (!empty($search_parts['uses_optional_indexes'])) {
        $search_candidates[] = buildOrderSearchQueryParts($search, 'o', 'c', 't', false);
    }

    $orders = [];
    $total = 0;
    $used_parts = $search_parts;

    foreach ($search_candidates as $candidate_index => $candidate_parts) {
        $used_parts = $candidate_parts;
        $where_clauses = [];
        $sql_params = [];

        if ($technicianId !== null) {
            $where_clauses[] = 'o.technician_id = ?';
            $sql_params[] = (int)$technicianId;
        }

        if (!empty($candidate_parts['where_clauses'])) {
            $where_clauses = array_merge($where_clauses, $candidate_parts['where_clauses']);
            $sql_params = array_merge($sql_params, $candidate_parts['where_params']);
        }

        if ($canonical_filter_status === 'Ready') {
            $where_clauses[] = buildStatusInCondition('o.status', $dashboard_status_groups['ready'], $sql_params);
        } elseif ($canonical_filter_status === 'In Repair') {
            $where_clauses[] = buildStatusInCondition('o.status', $dashboard_status_groups['progress'], $sql_params);
        } elseif ($filterStatus) {
            $where_clauses[] = buildStatusInCondition('o.status', [$filterStatus], $sql_params);
        }

        $where_sql = $where_clauses ? (' WHERE ' . implode(' AND ', $where_clauses)) : '';

        try {
            if ($includeTotal) {
                $count_stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM orders o'
                    . ' JOIN customers c ON o.customer_id = c.id'
                    . ' LEFT JOIN technicians t ON o.technician_id = t.id'
                    . $where_sql
                );
                $count_stmt->execute($sql_params);
                $total = (int)$count_stmt->fetchColumn();
            }

            $fetch_params = array_merge($candidate_parts['score_params'], $sql_params);
            $stmt = $pdo->prepare(
                'SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name, '
                . $candidate_parts['score_sql'] . ' AS search_relevance'
                . ' FROM orders o'
                . ' JOIN customers c ON o.customer_id = c.id'
                . ' LEFT JOIN technicians t ON o.technician_id = t.id'
                . $where_sql
                . ' ORDER BY search_relevance DESC, o.created_at DESC'
                . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
            );
            $stmt->execute($fetch_params);
            $orders = $stmt->fetchAll();
            if (!$includeTotal) {
                $total = count($orders);
            }
            break;
        } catch (PDOException $searchException) {
            if ($candidate_index === 0 && isset($search_candidates[1])) {
                error_log('order search indexed fallback: ' . $searchException->getMessage());
                $orders = [];
                $total = 0;
                continue;
            }
            throw $searchException;
        }
    }

    return [
        'orders' => $orders,
        'total' => $total,
        'search' => $search,
        'search_parts' => $used_parts,
    ];
}

function get_setting($key, $default = '') {
    global $pdo;
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $cache[$key] = $val;
            return $val;
        }
    } catch (Exception $e) {
        $cache[$key] = $default;
        return $default;
    }
    $cache[$key] = $default;
    return $default;
}

function set_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    return $stmt->execute([$key, $value]);
}

/**
 * Absolute path to the SQL backup directory (trailing separator).
 * Default: <app>/backup_db/  (never outside the application root).
 * Override with CRM_BACKUP_DIR only when the host requires a non-web path.
 *
 * @param bool $create Create the directory (and deny-web guards) when missing.
 */
function crmBackupDirectory(bool $create = false): string
{
    $configured = trim((string)(getenv('CRM_BACKUP_DIR') ?: ''));
    if ($configured !== '') {
        $dir = rtrim($configured, "/\\") . DIRECTORY_SEPARATOR;
    } else {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backup_db' . DIRECTORY_SEPARATOR;
    }

    if ($create) {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create backup directory.');
        }
        // Defense in depth: block direct HTTP even if root rewrite rules are missing.
        $htaccess = $dir . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Deny all direct web access to SQL dumps\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                . "Options -Indexes\n"
            );
        }
        $indexGuard = $dir . 'index.html';
        if (!is_file($indexGuard)) {
            @file_put_contents($indexGuard, '');
        }
    }

    return $dir;
}

function getDeviceBrands() {
    global $pdo;
    try {
        return $pdo->query("SELECT brand_name FROM device_brands ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return ['Apple', 'Samsung', 'Xiaomi', 'Other'];
    }
}

/**
 * Log System Error
 */
function log_error($message, $type = 'system', $details = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO system_errors (error_type, message, details) VALUES (?, ?, ?)");
        $stmt->execute([$type, $message, $details]);
    } catch (Exception $e) {
        // Fallback to file if DB fails
        error_log("DB Log Failed: " . $message . " | " . $details);
    }
}

function telegramHtml($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sendTelegramNotification($chatId, $message) {
    if (!defined('TG_BOT_TOKEN') || empty($chatId)) return false;

    if (!function_exists('curl_init')) {
        error_log('Telegram notification skipped: cURL extension is unavailable.');
        return false;
    }

    try {
        $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init();
        if ($ch === false) {
            return false;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        unset($ch);

        if ($response === false) return false;

        $result = json_decode($response, true);
        return isset($result['ok']) && $result['ok'];
    } catch (Throwable $e) {
        error_log('Telegram notification error: ' . $e->getMessage());
        return false;
    }
}

function getStatusChangeAdminTelegramId(): string {
    $chatId = trim((string)get_setting('status_change_admin_telegram_id', '2427615'));
    return $chatId !== '' ? $chatId : '2427615';
}

function sendOrderStatusAdminNotification($order_id, string $canonical_status, $final_cost = null): bool {
    $chatId = getStatusChangeAdminTelegramId();
    if ($chatId === '') {
        return false;
    }

    $msg = sprintf(__('tg_order_update_title'), $order_id) . "\n";
    $msg .= sprintf(__('tg_new_status'), getStatusLabel($canonical_status)) . "\n";
    if ($final_cost !== null && $final_cost !== '') {
        $msg .= sprintf(__('tg_cost'), formatMoney($final_cost)) . "\n";
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'app.servis.expert'));
    if ($host === '') {
        $host = 'app.servis.expert';
    }
    $link = $protocol . $host . '/view_order.php?id=' . (int)$order_id;
    $msg .= sprintf(__('tg_open_crm'), telegramHtml($link));

    return sendTelegramNotification($chatId, $msg);
}

function ensureOrderStatusLogTable() {
    global $pdo;
    $stmt = $pdo->query("SHOW TABLES LIKE 'order_status_log'");
    if (!$stmt || !$stmt->fetchColumn()) {
        throw new RuntimeException('Required migration is missing: order_status_log.');
    }
}

function logOrderStatusChange($order_id, $old_status, $new_status) {
    if ($old_status === $new_status && $old_status !== '') return;
    global $pdo;
    try {
        if (!$pdo->inTransaction()) {
            ensureOrderStatusLogTable();
        }
        $changed_by = $_SESSION['user_id'] ?? ($_SESSION['tech_id'] ?? null);
        $changed_role = $_SESSION['role'] ?? null;
        $stmt = $pdo->prepare(
            "INSERT INTO order_status_log (order_id, old_status, new_status, changed_by, changed_role)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$order_id, $old_status, $new_status, $changed_by, $changed_role]);
    } catch (Exception $e) {
        // Reports are built from this history; never lose a failure silently.
        error_log('logOrderStatusChange failed for order #' . (int)$order_id . ': ' . $e->getMessage());
    }
}

/**
 * Change inventory quantity safely, preventing negative stock.
 */
function changeInventoryQuantity($inventory_id, $change) {
    global $pdo;
    if (!$inventory_id) return true;
    
    $stmt = $pdo->prepare("SELECT quantity, part_name FROM inventory WHERE id = ? FOR UPDATE");
    $stmt->execute([$inventory_id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception("Inventory item #{$inventory_id} not found.");
    }
    
    $new_quantity = $item['quantity'] + $change;
    
    if ($new_quantity < 0) {
        throw new Exception("Not enough stock for item '{$item['part_name']}'. Available: {$item['quantity']}, Required: " . abs($change));
    }
    
    $upd = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
    return $upd->execute([$new_quantity, $inventory_id]);
}

/**
 * Process inventory changes when an order status changes.
 */
function processOrderInventoryChange($order_id, $is_finishing, $was_finished) {
    global $pdo;
    
    if (!$was_finished && $is_finishing) {
        $stmt = $pdo->prepare('SELECT inventory_id, quantity FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL');
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll();
        foreach ($items as $item) {
            changeInventoryQuantity($item['inventory_id'], -$item['quantity']);
        }
    } elseif ($was_finished && !$is_finishing) {
        $stmt = $pdo->prepare('SELECT inventory_id, quantity FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL');
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll();
        foreach ($items as $item) {
            changeInventoryQuantity($item['inventory_id'], $item['quantity']);
        }
    }
}

/**
 * Alphabet for public status tokens (8 chars). Excludes ambiguous 0/O/1/I/L.
 */
function crmPublicStatusTokenAlphabet(): string
{
    return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
}

/**
 * Generate a cryptographically random 8-character public status token.
 */
function crmGeneratePublicStatusToken(): string
{
    $alphabet = crmPublicStatusTokenAlphabet();
    $len = strlen($alphabet);
    $token = '';
    $bytes = random_bytes(8);
    for ($i = 0; $i < 8; $i++) {
        $token .= $alphabet[ord($bytes[$i]) % $len];
    }
    return $token;
}

/**
 * Normalize a public status token from a query string.
 */
function crmNormalizePublicStatusToken(?string $token): string
{
    $token = strtoupper(trim((string)$token));
    if ($token === '' || !preg_match('/^[A-Z2-9]{8}$/', $token)) {
        return '';
    }
    // Reject characters outside the chosen alphabet (I, L, O, 0, 1 excluded).
    if (strspn($token, crmPublicStatusTokenAlphabet()) !== 8) {
        return '';
    }
    return $token;
}

/**
 * Public customer-facing status URL on the CRM host (app.servis.expert).
 */
function crmOrderPublicStatusUrl(string $token): string
{
    $base = 'https://app.servis.expert/status.php';
    $token = crmNormalizePublicStatusToken($token);
    if ($token === '') {
        return $base;
    }
    return $base . '?id=' . rawurlencode($token);
}

/**
 * Ensure the order has a unique public_status_token; create one if missing.
 * Returns the 8-char token, or '' when the column is unavailable.
 */
function crmEnsureOrderPublicStatusToken(PDO $pdo, int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }

    try {
        $stmt = $pdo->prepare('SELECT public_status_token FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $existing = $stmt->fetchColumn();
        if (is_string($existing) && crmNormalizePublicStatusToken($existing) !== '') {
            return strtoupper($existing);
        }
    } catch (Throwable $e) {
        // Column may not exist yet before migration 005.
        error_log('crmEnsureOrderPublicStatusToken read failed: ' . $e->getMessage());
        return '';
    }

    $update = $pdo->prepare(
        'UPDATE orders SET public_status_token = ? WHERE id = ? AND (public_status_token IS NULL OR public_status_token = \'\')'
    );

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $token = crmGeneratePublicStatusToken();
        try {
            $update->execute([$token, $orderId]);
            if ($update->rowCount() > 0) {
                return $token;
            }
            // Another writer may have set it; re-read.
            $stmt = $pdo->prepare('SELECT public_status_token FROM orders WHERE id = ? LIMIT 1');
            $stmt->execute([$orderId]);
            $existing = $stmt->fetchColumn();
            if (is_string($existing) && crmNormalizePublicStatusToken($existing) !== '') {
                return strtoupper($existing);
            }
        } catch (PDOException $e) {
            // Unique collision — try another token.
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                error_log('crmEnsureOrderPublicStatusToken write failed: ' . $e->getMessage());
                return '';
            }
        }
    }

    return '';
}

/**
 * Load a minimal public status payload by opaque token (no secrets).
 *
 * @return array<string,mixed>|null
 */
function crmGetPublicOrderStatusByToken(PDO $pdo, string $token): ?array
{
    $token = crmNormalizePublicStatusToken($token);
    if ($token === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT o.id, o.public_status_token, o.status, o.device_type, o.device_brand, o.device_model,
                    o.created_at, o.updated_at, o.order_type
             FROM orders o
             WHERE o.public_status_token = ?
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('crmGetPublicOrderStatusByToken failed: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }

    $status = canonicalOrderStatus($row['status'] ?? '');
    $device = trim(($row['device_brand'] ?? '') . ' ' . ($row['device_model'] ?? ''));

    // Minimal public payload: status + device context only. No customer PII.
    return [
        'public_id' => strtoupper((string)$row['public_status_token']),
        'order_number' => (int)$row['id'],
        'status' => $status,
        'status_label' => getStatusLabel($status),
        'device' => $device !== '' ? $device : '—',
        'device_type' => (string)($row['device_type'] ?? ''),
        'order_type' => (string)($row['order_type'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}
?>
