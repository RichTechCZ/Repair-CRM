<?php
/**
 * Liveness/readiness probe for monitoring and atomic release activation.
 *
 * 200 {"status":"ok"} when the database answers, every numbered migration has been applied
 * and uploads/ is writable; otherwise 503 with the failing check names. The verified release commit is returned in X-CRM-Release. Stateless: no session is started.
 */
define('CRM_STATELESS', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/release_info.php';

$checks = ['database' => false, 'migrations' => false, 'uploads' => false];

try {
    $checks['database'] = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;

    $inventory = crmMigrationInventory($pdo, __DIR__);
    $checks['migrations'] = array_diff($inventory['files'], $inventory['applied']) === [];
} catch (Throwable $e) {
    error_log('health.php: ' . $e->getMessage());
}

$uploads = __DIR__ . '/uploads';
$checks['uploads'] = is_dir($uploads) && is_writable($uploads);

$healthy = !in_array(false, $checks, true);
http_response_code($healthy ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$revision = crmInstalledRevision(__DIR__);
if ($revision !== '') {
    header('X-CRM-Release: ' . $revision);
}
echo json_encode([
    'status' => $healthy ? 'ok' : 'unavailable',
    'failed' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
]);
