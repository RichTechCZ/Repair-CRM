<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit(__('access_denied_msg'));
}

$filename = (string)($_GET['file'] ?? '');
if (!preg_match('/^backup_[A-Za-z0-9_.-]+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename)) {
    http_response_code(400);
    exit('Invalid backup name');
}

$configuredBackupDir = trim((string)(getenv('CRM_BACKUP_DIR') ?: ''));
$backupDir = $configuredBackupDir !== ''
    ? rtrim($configuredBackupDir, "/\\") . DIRECTORY_SEPARATOR
    : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'crm_backups' . DIRECTORY_SEPARATOR;

$base = realpath($backupDir);
$path = realpath($backupDir . $filename);

if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('Backup not found');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
