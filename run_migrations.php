<?php
/**
 * CRM migration runner.
 *
 * CLI: php run_migrations.php
 * Web: always disabled (410). Migrations are CLI-only in every environment.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/migration_runner.php';

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $migrationUser = trim((string)(getenv('DB_MIGRATION_USER') ?: ''));
    $migrationPass = (string)(getenv('DB_MIGRATION_PASS') ?: '');
    if ($crmIsProduction && ($migrationUser === '' || $migrationPass === '')) {
        fwrite(STDERR, "Production migrations require DB_MIGRATION_USER and DB_MIGRATION_PASS.\n");
        exit(1);
    }
    if ($crmIsProduction && in_array(strtolower($migrationUser), ['root', 'admin', 'administrator'], true)) {
        fwrite(STDERR, "Privileged migration database accounts are forbidden in production.\n");
        exit(1);
    }
    if ($migrationUser !== '') {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . DB_PORT_DSN . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                $migrationUser,
                $migrationPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('Migration DB connection error: ' . $e->getMessage());
            fwrite(STDERR, "Unable to connect with the migration database account.\n");
            exit(1);
        }
    }
}

if (!$isCli) {
    // Web execution is disabled in every environment. Migrations are CLI-only.
    http_response_code(410);
    exit('Web migrations are disabled. Run php run_migrations.php from the deployment CLI.');
}

$results = crmRunMigrations($pdo, __DIR__);
$hasError = crmMigrationResultsContainError($results);

foreach ($results as $result) {
    $file = $result['file'] ?? '(migration system)';
    $status = strtoupper((string)($result['status'] ?? 'unknown'));
    $message = isset($result['message']) ? ' — ' . $result['message'] : '';
    echo "{$status}: {$file}{$message}\n";
}
exit($hasError ? 1 : 0);
