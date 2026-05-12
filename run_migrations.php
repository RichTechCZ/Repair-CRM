<?php
/**
 * CRM Migration Runner
 * -------------------
 * Scans ./migrations/ for *.sql files (sorted alphabetically) and
 * executes any that have not yet been recorded in the `migrations` table.
 *
 * Usage (CLI):
 *   php run_migrations.php
 *
 * Usage (web – admin only):
 *   Open https://your-domain/run_migrations.php while logged in as admin.
 */

require_once __DIR__ . '/includes/config.php';

// ── Auth guard (web) ──────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/functions.php';
    if (empty($_SESSION['user_id']) || !hasPermission('admin_access')) {
        http_response_code(403);
        die('<h1>403 Forbidden</h1>');
    }
    echo '<pre>';
}

// ── Bootstrap: ensure migrations table exists ─────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `migration_name` VARCHAR(255)   NOT NULL UNIQUE,
        `executed_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Load already-executed migrations ─────────────────────────────────────────
$executed = $pdo->query("SELECT migration_name FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

// ── Scan migration files ──────────────────────────────────────────────────────
$files = glob(__DIR__ . '/migrations/*.sql');
if (!$files) {
    echo "No migration files found.\n";
    exit(0);
}
sort($files);

$ok = 0; $skip = 0; $fail = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $executed, true)) {
        echo "SKIP : $name\n";
        $skip++;
        continue;
    }

    $sql = file_get_contents($file);
    try {
        // Split on semicolons so multi-statement files work
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)")->execute([$name]);
        echo "OK   : $name\n";
        $ok++;
    } catch (Throwable $e) {
        echo "ERROR: $name — " . $e->getMessage() . "\n";
        $fail++;
        break; // Stop on first failure to preserve consistency
    }
}

echo "\nDone. OK=$ok  SKIP=$skip  FAIL=$fail\n";

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
}
