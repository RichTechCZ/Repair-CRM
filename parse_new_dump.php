<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

require_once 'includes/config.php';

$sql_file = "H:/MY PROJECT/crm/migrations/richtechcz_db001_2026-02-05_18-25_d1f7e.sql";
function log_msg($msg) { echo $msg . "\n"; }

$data = [
    'users' => [],
    'quittance' => [],
    'brand' => [],
    'device' => [],
    'statuslist' => [],
    'repair_type' => [],
    'engineers' => [],
    'comments' => [],
    'repair' => []
];

log_msg("Parsing NEW SQL file: $sql_file");

$handle = fopen($sql_file, "r");
$current_table = '';
$buffer = '';

while (($line = fgets($handle)) !== false) {
    if (preg_match('/INSERT INTO `([^`]+)` VALUES/i', $line, $m)) {
        $current_table = $m[1];
        $buffer = '';
        continue;
    }
    
    if ($current_table && (
        preg_match('/^(CREATE|DROP|ALTER|--|\/\*)/i', trim($line)) ||
        preg_match('/^LOCK TABLES/i', trim($line)) ||
        preg_match('/^UNLOCK TABLES/i', trim($line))
    )) {
        $current_table = '';
        $buffer = '';
        continue;
    }

    if ($current_table && isset($data[$current_table])) {
        $buffer .= $line;
        if (preg_match('/\),[\s\r\n]*$/', $buffer) || preg_match('/\);[\s\r\n]*$/', $buffer)) {
            $trimmed = trim($buffer);
            $trimmed = rtrim($trimmed, ',;');
            if (strpos($trimmed, '(') === 0) $trimmed = substr($trimmed, 1);
            if (substr($trimmed, -1) === ')') $trimmed = substr($trimmed, 0, -1);
            
            $row = str_getcsv($trimmed, ",", "'", "\\");
            if ($row && count($row) > 1) {
                if (is_numeric(trim($row[0]))) {
                    $data[$current_table][] = array_map('trim', $row);
                }
            }
            $buffer = '';
        }
    }
}
fclose($handle);

log_msg("\nNew File Summary:");
foreach ($data as $table => $rows) { log_msg("  $table: " . count($rows) . " rows"); }
