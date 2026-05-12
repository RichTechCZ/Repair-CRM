<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$sql_file = "H:/MY PROJECT/crm/migrations/richtechcz_db001_2026-02-05_18-25_d1f7e.sql";
$handle = fopen($sql_file, "r");
$current_table = '';
while (($line = fgets($handle)) !== false) {
    if (strpos($line, 'INSERT INTO `quittance`') !== false) {
        $current_table = 'quittance';
        continue;
    }
    if ($current_table == 'quittance') {
        if (strpos($line, '(10755,') !== false) {
            echo "Line found: " . trim($line) . "\n";
            break;
        }
    }
}
fclose($handle);
