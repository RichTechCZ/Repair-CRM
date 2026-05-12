<?php
require_once 'includes/config.php';

$sync_token = trim((string)(getenv('SYNC_TOKEN') ?: ''));
if ($sync_token === '') {
    http_response_code(503);
    echo "SYNC_TOKEN is not configured\n";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', php_sapi_name() === 'cli' ? '1' : '0');

if (php_sapi_name() !== 'cli') {
    $provided_token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if ($provided_token === '' || !hash_equals($sync_token, $provided_token)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}


if (!isset($pdo)) {
    die("PDO not initialized. Check config.php\n");
}

if (php_sapi_name() === 'cli') {
    echo "Working directory: " . getcwd() . "\n";
}

function sync_orders($data) {
    global $pdo;
    $updated = 0;
    foreach ($data as $item) {
        $id = intval($item['id']);
        if (!$id) continue;
        
        $zap = $item['zap']; // d.m.Y or null
        $amt = floatval($item['amt']);
        
        $shipping_date = null;
        if ($zap && $zap !== '-') {
            $d = DateTime::createFromFormat('j.m.Y', $zap);
            if ($d) $shipping_date = $d->format('Y-m-d H:i:s');
        }

        // Check local
        $stmt = $pdo->prepare("SELECT final_cost, status, shipping_date FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $local = $stmt->fetch();
        
        if ($local) {
            $needs_update = false;
            $upd_fields = [];
            $params = [];
            
            // 1. Amount
            if (abs(floatval($local['final_cost']) - $amt) > 0.01) {
                $upd_fields[] = "final_cost = ?";
                $params[] = $amt;
                $needs_update = true;
            }
            
            // 2. Status & Shipping Date
            if ($shipping_date) {
                if ($local['status'] !== 'Collected') {
                    $upd_fields[] = "status = 'Collected'";
                    $needs_update = true;
                }
                // Only update shipping_date if it differs significantly
                if (!$local['shipping_date'] || abs(strtotime($local['shipping_date']) - strtotime($shipping_date)) > 86400) {
                    $upd_fields[] = "shipping_date = ?";
                    $params[] = $shipping_date;
                    $needs_update = true;
                }
            }

            if ($needs_update) {
                $params[] = $id;
                $sql = "UPDATE orders SET " . implode(', ', $upd_fields) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($params);
                
                // Also update related invoice if exists
                $stmt_inv = $pdo->prepare("UPDATE invoices SET total_amount = ?, status = 'issued' WHERE order_id = ?");
                $stmt_inv->execute([$amt, $id]);
                
                $updated++;
                echo "Updated Order #$id\n";
            }
        }
    }
    return $updated;
}

// Data will be passed via temporary file or similar
if (file_exists('temp_sync_data.json')) {
    $data = json_decode(file_get_contents('temp_sync_data.json'), true);
    if ($data) {
        $count = sync_orders($data);
        echo "Sync finished. Total updated: $count\n";
    }
}
