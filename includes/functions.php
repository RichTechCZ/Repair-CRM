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
        if (!isset($_SESSION['_perms'])) {
            $stmt = $pdo->prepare('SELECT permission FROM tech_permissions WHERE technician_id = ?');
            $stmt->execute([$_SESSION['tech_id']]);
            $_SESSION['_perms'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // admin_access grants everything
        if (in_array('admin_access', $_SESSION['_perms'], true)) {
            return true;
        }

        return in_array($permission, $_SESSION['_perms'], true);
    }

    return false;
}

/**
 * Invalidate the in-session permissions cache.
 * Call after setTechPermissions() or on logout.
 */
function invalidatePermissionsCache(): void {
    unset($_SESSION['_perms']);
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
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Set permissions for a technician (replaces all existing)
 */
function setTechPermissions($tech_id, $permissions) {
    global $pdo;
    
    // Delete existing
    $stmt = $pdo->prepare("DELETE FROM tech_permissions WHERE technician_id = ?");
    $stmt->execute([$tech_id]);
    
    // Insert new
    if (!empty($permissions)) {
        $stmt = $pdo->prepare("INSERT INTO tech_permissions (technician_id, permission) VALUES (?, ?)");
        foreach ($permissions as $perm) {
            $stmt->execute([$tech_id, $perm]);
        }
    }

    // Invalidate session permission cache so changes take effect immediately
    invalidatePermissionsCache();
}

/**
 * Available permissions list with descriptions
 */
function getAvailablePermissions() {
    return [
        'admin_access' => ['name' => __('perm_admin_access'), 'desc' => __('perm_admin_access_desc'), 'icon' => 'fas fa-crown text-warning'],
        'view_all_orders' => ['name' => __('perm_view_all_orders'), 'desc' => __('perm_view_all_orders_desc'), 'icon' => 'fas fa-eye text-info'],
        'edit_orders' => ['name' => __('perm_edit_orders'), 'desc' => __('perm_edit_orders_desc'), 'icon' => 'fas fa-edit text-primary'],
        'edit_customers' => ['name' => __('perm_edit_customers'), 'desc' => __('perm_edit_customers_desc'), 'icon' => 'fas fa-user-edit text-success'],
        'manage_passwords' => ['name' => __('perm_manage_passwords'), 'desc' => __('perm_manage_passwords_desc'), 'icon' => 'fas fa-key text-danger'],
    ];
}

function getDeviceIcon($type) {
    switch ($type) {
        case 'Phone': return '📱';
        case 'Notebook': return '💻';
        case 'PC': return '🖥️';
        case 'Tablet': return '📟';
        case 'HDD': return '💾';
        case 'Computer': return '🖥️';
        default: return '🛠️';
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'New':
            return '<span class="badge bg-primary">'.__('new').'</span>';
        case 'Pending Approval':
            return '<span class="badge bg-info text-dark">'.__('pending_approval').'</span>';
        case 'In Progress':
            return '<span class="badge bg-warning">'.__('in_progress').'</span>';
        case 'Waiting for Parts':
            return '<span class="badge bg-secondary">'.__('waiting_parts').'</span>';
        case 'Completed':
            return '<span class="badge bg-success">'.__('status_completed').'</span>';
        case 'Collected':
            return '<span class="badge bg-info text-dark">'.__('status_collected').'</span>';
        case 'Cancelled':
            return '<span class="badge bg-danger">'.__('status_cancelled').'</span>';
        default:
            return '<span class="badge bg-dark">' . $status . '</span>';
    }
}

function formatMoney($amount) {
    global $pdo;
    $currency = get_setting('currency', 'Kč');
    return number_format($amount, 2, '.', ' ') . ' ' . $currency;
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

function sendTelegramNotification($chatId, $message) {
    if (!defined('TG_BOT_TOKEN') || empty($chatId)) return false;
    
    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
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
}

function ensureOrderStatusLogTable() {
    global $pdo;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_status_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            old_status VARCHAR(50) NOT NULL,
            new_status VARCHAR(50) NOT NULL,
            changed_by INT NULL,
            changed_role VARCHAR(20) NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
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
        // ignore logging errors
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
?>
