<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once 'includes/config.php';

$sql_file = "H:/MY PROJECT/crm/migrations/richtechcz_db001_2026-02-05_18-25_d1f7e.sql";
if (!file_exists($sql_file)) die("Error: SQL file not found at $sql_file\n");

echo "Checking data integrity...\n";
echo "SQL File: $sql_file\n";

// 1. Parse SQL file for original quittance data
$handle = fopen($sql_file, "r");
$quittances_in_sql = [];
$current_table = '';
$buffer = '';

while (($line = fgets($handle)) !== false) {
    if (preg_match('/INSERT INTO `([^`]+)` VALUES/i', $line, $m)) {
        $current_table = $m[1];
        $buffer = '';
        continue;
    }
    
    if ($current_table == 'quittance') {
        // Stop if we hit something else
        if (preg_match('/^(CREATE|DROP|ALTER|--|\/\*|LOCK TABLES|UNLOCK TABLES)/i', trim($line))) {
            $current_table = '';
            continue;
        }

        $buffer .= $line;
        if (preg_match('/\),[\s\r\n]*$/', $buffer) || preg_match('/\);[\s\r\n]*$/', $buffer)) {
            $trimmed = trim($buffer);
            $trimmed = rtrim($trimmed, ',;');
            if (strpos($trimmed, '(') === 0) $trimmed = substr($trimmed, 1);
            if (substr($trimmed, -1) === ')') $trimmed = substr($trimmed, 0, -1);
            
            $row = str_getcsv($trimmed, ",", "'", "\\");
            if ($row && is_numeric(trim($row[0]))) {
                $id = (int)trim($row[0]);
                $quittances_in_sql[$id] = [
                    'id' => $id,
                    'model' => trim($row[5] ?? ''),
                    'sn' => trim($row[6] ?? ''),
                    'date' => trim($row[12] ?? '')
                ];
            }
            $buffer = '';
        }
    }
}
fclose($handle);

$sql_count = count($quittances_in_sql);
echo "Orders found in SQL file: $sql_count\n";

// 2. Fetch data from DB
$stmt = $pdo->query("SELECT id, device_model, serial_number, created_at FROM orders");
$db_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$db_count = count($db_orders);
$db_by_id = [];
foreach ($db_orders as $order) {
    $db_by_id[(int)$order['id']] = $order;
}

echo "Orders found in Database: $db_count\n";

// 3. Comparison
$missing_ids = [];
$mismatch_details = [];

foreach ($quittances_in_sql as $id => $sql_data) {
    if (!isset($db_by_id[$id])) {
        $missing_ids[] = $id;
        continue;
    }
    
    $db_data = $db_by_id[$id];
    
    // Basic verification of model and date
    // Note: Dates might be formatted differently, but SQL usually has YYYY-MM-DD
    $db_date = substr($db_data['created_at'], 0, 10);
    $sql_date = ($sql_data['date'] == '0000-00-00' || empty($sql_data['date'])) ? '' : $sql_data['date'];
    
    // We only log mismatches if both have data and they differ significantly
    // (Ignoring date mismatch if DB has today's date for '0000-00-00' from SQL)
    if ($sql_data['model'] !== $db_data['device_model']) {
        $mismatch_details[] = "Order #$id: Model mismatch (SQL: '{$sql_data['model']}', DB: '{$db_data['device_model']}')";
    }
}

// Check if DB has extra IDs
$extra_ids = [];
foreach ($db_by_id as $id => $data) {
    if (!isset($quittances_in_sql[$id])) {
        $extra_ids[] = $id;
    }
}

echo "\nSummary:\n";
if ($sql_count === $db_count && empty($missing_ids) && empty($extra_ids)) {
    echo "✅ COUNTS MATCH: $sql_count orders.\n";
} else {
    echo "❌ COUNT MISMATCH!\n";
    echo "  SQL Count: $sql_count\n";
    echo "  DB Count: $db_count\n";
}

if (!empty($missing_ids)) {
    echo "❌ MISSING IDs in DB (" . count($missing_ids) . "): " . implode(", ", array_slice($missing_ids, 0, 20)) . (count($missing_ids) > 20 ? "..." : "") . "\n";
} else {
    echo "✅ No missing IDs found.\n";
}

if (!empty($extra_ids)) {
    echo "ℹ️ EXTRA IDs in DB (" . count($extra_ids) . "): " . implode(", ", array_slice($extra_ids, 0, 20)) . (count($extra_ids) > 20 ? "..." : "") . "\n";
}

if (empty($mismatch_details)) {
    echo "✅ DATA SAMPLES MATCH (checked model names).\n";
} else {
    echo "❌ DATA MISMATCHES found (" . count($mismatch_details) . "):\n";
    foreach (array_slice($mismatch_details, 0, 10) as $msg) echo "  $msg\n";
    if (count($mismatch_details) > 10) echo "  ...\n";
}
