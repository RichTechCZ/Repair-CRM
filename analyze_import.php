<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once 'includes/config.php';

/**
 * Legacy Import & Analysis Script
 * Assumptions:
 * 1. Legacy tables are imported into the same database.
 * 2. This script performs mapping and duplicate checks.
 */

header('Content-Type: text/plain; charset=utf-8');

function log_msg($msg) {
    echo $msg . "\n";
}

try {
    log_msg("--- Legacy Data Analysis Start ---");

    // 1. Check if legacy tables exist
    $required_tables = ['quittance', 'users', 'device', 'statuslist', 'brand', 'repair_type', 'engineers', 'comments', 'repair'];
    foreach ($required_tables as $table) {
        $check = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
        if (!$check) {
            die("Error: Legacy table '$table' not found. Please import the SQL file first.\n");
        }
    }

    // 2. Analyze Customers (users table)
    $stmt = $pdo->query("SELECT phone, COUNT(*) as cnt FROM users GROUP BY phone HAVING cnt > 1");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    log_msg("Duplicate phones found in legacy 'users': " . count($duplicates));
    foreach ($duplicates as $d) {
        log_msg("  Phone: {$d['phone']} (Records: {$d['cnt']})");
    }

    // 3. Mapping Analysis
    // Statuses
    log_msg("\n--- Status Mapping ---");
    $statuses = $pdo->query("SELECT * FROM statuslist")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statuses as $s) {
        $mapped = "New"; // Default
        if (strpos($s['statusname'], 'práci') !== false) $mapped = "In Progress";
        if (strpos($s['statusname'], 'připraven') !== false) $mapped = "Completed";
        if (strpos($s['statusname'], 'zrušen') !== false) $mapped = "Cancelled";
        if (strpos($s['statusname'], 'dil') !== false) $mapped = "Waiting for Parts";
        log_msg("  Legacy: '{$s['statusname']}' -> New CRM: '$mapped'");
    }

    // Repair Types
    log_msg("\n--- Repair Type Mapping ---");
    $types = $pdo->query("SELECT * FROM repair_type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($types as $t) {
        $mapped = (strpos($t['type_repair'], 'nezáruční') !== false) ? "Non-Warranty" : "Warranty";
        log_msg("  Legacy: '{$t['type_repair']}' -> New CRM: '$mapped'");
    }

    // 4. Data Summary
    $order_count = $pdo->query("SELECT COUNT(*) FROM quittance")->fetchColumn();
    $user_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    log_msg("\nTotal orders to import: $order_count");
    log_msg("Total customers to import: $user_count");

    log_msg("\n--- Ready for import. Run the migration script next. ---");

} catch (Exception $e) {
    log_msg("Error: " . $e->getMessage());
}
