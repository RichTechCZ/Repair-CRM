<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => 'backup_db',
]);

try {
    // Default store: app/backup_db/ (web-denied by root .htaccess + local guard).
    $backupDir = crmBackupDirectory(true);
} catch (Throwable $e) {
    error_log('backup_db directory: ' . $e->getMessage());
    api_json_exit(['success' => false, 'message' => 'Unable to create backup directory.'], 500);
}

// Generate filename
$safeDatabaseName = preg_replace('/[^A-Za-z0-9_.-]/', '_', DB_NAME);
$filename = 'backup_' . $safeDatabaseName . '_' . date('Y-m-d_H-i-s') . '.sql';
$filePath = $backupDir . $filename;

$handle = null;
try {
    $handle = fopen($filePath, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to create backup file.');
    }
    @chmod($filePath, 0600);
    $write = static function (string $content) use ($handle): void {
        if (fwrite($handle, $content) === false) {
            throw new RuntimeException('Failed to write backup file.');
        }
    };

    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();

    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    $result->closeCursor();
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    }

    $write("-- CRM Database Backup\n");
    $write("-- Date: " . date('Y-m-d H:i:s') . "\n");
    $write("-- Database: " . DB_NAME . "\n\n");
    $write("SET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tables as $table) {
        $table_safe = str_replace('`', '``', $table);
        // Drop + recreate so the dump can be restored over an existing schema
        $write("\nDROP TABLE IF EXISTS `$table_safe`;\n");
        // Table structure
        $stmt = $pdo->query("SHOW CREATE TABLE `$table_safe`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $write($row[1] . ";\n\n");
        $stmt->closeCursor();

        // Table data
        $stmt = $pdo->query("SELECT * FROM `$table_safe`");
        $columnCount = $stmt->columnCount();

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $values = [];
            for ($j = 0; $j < $columnCount; $j++) {
                if (!isset($row[$j])) {
                    $values[] = 'NULL';
                } else {
                    // Use the driver's native quoting: it respects the connection
                    // charset and is safe for multibyte/binary data, unlike addslashes().
                    $values[] = $pdo->quote((string)$row[$j]);
                }
            }
            $write("INSERT INTO `$table_safe` VALUES(" . implode(',', $values) . ");\n");
        }
        $write("\n");
    }

    $write("\nSET FOREIGN_KEY_CHECKS=1;\n");
    $pdo->commit();
    fflush($handle);
    fclose($handle);
    $handle = null;

    echo json_encode([
        'success' => true,
        'filename' => $filename,
        'path' => 'api/download_backup.php?file=' . rawurlencode($filename),
        'download_url' => 'api/download_backup.php?file=' . rawurlencode($filename)
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_resource($handle)) {
        fclose($handle);
    }
    if (is_file($filePath)) {
        @unlink($filePath);
    }
    error_log('backup_db error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
