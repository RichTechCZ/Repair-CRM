<?php
/**
 * Liveness/readiness probe for monitoring and atomic release activation.
 *
 * 200 {"status":"ok"} when the database answers, every numbered migration has been applied
 * and uploads/ is writable; otherwise 503 with the failing check names. No versions, paths,
 * hostnames or error texts are exposed. Stateless: no session is started.
 */
define('CRM_STATELESS', true);
require_once __DIR__ . '/includes/config.php';

$checks = ['database' => false, 'migrations' => false, 'uploads' => false];

try {
    $checks['database'] = (int)$pdo->query('SELECT 1')->fetchColumn() === 1;

    $expected = array_map('basename', array_filter(
        glob(__DIR__ . '/migrations/*.sql') ?: [],
        static fn(string $file): bool => (bool)preg_match('/^\d{3}_[A-Za-z0-9_]+\.sql$/', basename($file))
    ));
    $applied = $pdo->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $checks['migrations'] = array_diff($expected, $applied) === [];
} catch (Throwable $e) {
    error_log('health.php: ' . $e->getMessage());
}

$uploads = __DIR__ . '/uploads';
$checks['uploads'] = is_dir($uploads) && is_writable($uploads);

$healthy = !in_array(false, $checks, true);
http_response_code($healthy ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'status' => $healthy ? 'ok' : 'unavailable',
    'failed' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
]);
