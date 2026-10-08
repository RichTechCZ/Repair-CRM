<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'auth' => true,
    'post' => false,
    'csrf' => false,
    'permission' => 'admin_access',
    'rate' => ['action' => 'download_backup', 'max' => 20, 'window' => 60],
    'json' => false,
]);

// Prefer POST with CSRF; allow GET only with a valid csrf_token query param
// so the settings UI can still trigger a same-origin download without leaking
// open CSRF-less GET endpoints.
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$token = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
if ($method === 'POST') {
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        exit(__('csrf_token_invalid'));
    }
} else {
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        exit(__('csrf_token_invalid'));
    }
}

$filename = (string)($_POST['file'] ?? $_GET['file'] ?? '');
if (!preg_match('/^backup_[A-Za-z0-9_.-]+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/', $filename)) {
    http_response_code(400);
    exit('Invalid backup name');
}

$backupDir = crmBackupDirectory(false);
$base = realpath($backupDir);
// realpath() fails when the directory was just created or path is not yet resolved;
// fall back to the logical directory when it already exists.
if ($base === false && is_dir($backupDir)) {
    $base = realpath(rtrim($backupDir, "/\\"));
}
$path = $base !== false ? realpath($base . DIRECTORY_SEPARATOR . $filename) : false;

if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404);
    exit('Backup not found');
}

header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
