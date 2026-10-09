<?php
/**
 * Housekeeping job (CLI only). Schedule hourly, e.g.:
 *   0 * * * * cd /path/to/crm && php cron.php >> /var/log/crm-cron.log 2>&1
 *
 * Removes expired operational rows and keeps a bounded number of database backups.
 * Uses the least-privilege web account (DELETE only); no schema changes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

const CRM_BACKUPS_TO_KEEP = 14;

$tasks = [
    // Abandoned Telegram dialogs (a user started "enter final cost" and never answered).
    'telegram_bot_states' => 'DELETE FROM telegram_bot_states WHERE updated_at < NOW() - INTERVAL 1 DAY',
    // Rate limiter windows are at most minutes long.
    'rate_limits' => 'DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 HOUR',
    // Login throttle window is 5 minutes; keep a day for incident review.
    'login_attempts' => 'DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY',
    // Error log 90 days; the audit trail (admin password changes etc.) is kept for a year.
    'system_errors' => "DELETE FROM system_errors WHERE (error_type <> 'audit' AND created_at < NOW() - INTERVAL 90 DAY)
                        OR (error_type = 'audit' AND created_at < NOW() - INTERVAL 365 DAY)",
];

$failed = false;
foreach ($tasks as $name => $sql) {
    try {
        $deleted = $pdo->exec($sql);
        echo date('c') . " {$name}: deleted {$deleted}\n";
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, date('c') . " {$name}: failed: " . $e->getMessage() . "\n");
    }
}

// Backup rotation: keep the newest N SQL dumps.
try {
    $backups = glob(crmBackupDirectory() . '*.sql') ?: [];
    usort($backups, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($backups, CRM_BACKUPS_TO_KEEP) as $oldBackup) {
        if (@unlink($oldBackup)) {
            echo date('c') . ' backup removed: ' . basename($oldBackup) . "\n";
        }
    }
} catch (Throwable $e) {
    $failed = true;
    fwrite(STDERR, date('c') . ' backup rotation failed: ' . $e->getMessage() . "\n");
}

// Legacy on-disk accounting exports (exports are now streamed and never written).
foreach (glob(__DIR__ . '/temp/exports/*') ?: [] as $legacyExport) {
    if (is_file($legacyExport) && filemtime($legacyExport) < time() - 3600) {
        @unlink($legacyExport);
    }
}

exit($failed ? 1 : 0);
