<?php
/**
 * API: Run CRM Update via git pull
 * Executes 'git pull origin main' on the server to update the CRM files.
 * 
 * SECURITY: Admin-only, CSRF-protected.
 * IMPORTANT: The web server user (www-data / apache) must have write access 
 *            to the CRM directory and git must be installed on the server.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins
if (($_SESSION['role'] ?? '') !== 'admin' && !hasPermission('admin_access')) {
    echo json_encode(['success' => false, 'message' => __('access_denied')]);
    exit;
}

// CSRF check
if (!validateCsrfToken($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => __('csrf_invalid')]);
    exit;
}

$projectDir = realpath(__DIR__ . '/..');

// Check if git is available
$gitCheck = shell_exec('git --version 2>&1');
if (strpos($gitCheck, 'git version') === false) {
    echo json_encode([
        'success' => false, 
        'message' => 'Git is not installed on this server.',
        'step'    => 'git_check',
    ]);
    exit;
}

// Check if this is a git repo
if (!is_dir($projectDir . '/.git')) {
    echo json_encode([
        'success' => false,
        'message' => 'Project directory is not a git repository. Please initialize git on the server first.',
        'step'    => 'git_repo_check',
        'hint'    => 'Run: cd ' . $projectDir . ' && git init && git remote add origin https://github.com/RichTechCZ/Repair-CRM.git && git fetch && git checkout main',
    ]);
    exit;
}

// Save pre-update version
$preVersionFile = $projectDir . '/version.json';
$preVersion = file_exists($preVersionFile) ? json_decode(file_get_contents($preVersionFile), true) : null;

// Stash any local changes (safety net for .env, uploads, etc.)
$stashOutput = shell_exec("cd " . escapeshellarg($projectDir) . " && git stash 2>&1");

// Pull latest from GitHub
$pullOutput = shell_exec("cd " . escapeshellarg($projectDir) . " && git pull origin main 2>&1");

// Check for errors
$isError = (
    strpos($pullOutput, 'fatal:') !== false ||
    strpos($pullOutput, 'error:') !== false ||
    strpos($pullOutput, 'CONFLICT') !== false
);

if ($isError) {
    // Try to recover
    shell_exec("cd " . escapeshellarg($projectDir) . " && git stash pop 2>&1");
    
    echo json_encode([
        'success' => false,
        'message' => 'Git pull failed',
        'output'  => $pullOutput,
        'step'    => 'git_pull',
    ]);
    exit;
}

// Pop stash if there was one
if (strpos($stashOutput, 'No local changes') === false) {
    $stashPopOutput = shell_exec("cd " . escapeshellarg($projectDir) . " && git stash pop 2>&1");
}

// Read new version
$postVersion = file_exists($preVersionFile) ? json_decode(file_get_contents($preVersionFile), true) : null;

// Run migrations if needed
$migrationsDir = $projectDir . '/migrations';
$migrationResults = [];
if (is_dir($migrationsDir)) {
    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles);
    
    // Get list of already-run migrations
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        $executed = $pdo->query("SELECT filename FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($migrationFiles as $migFile) {
            $basename = basename($migFile);
            if (!in_array($basename, $executed)) {
                try {
                    $sql = file_get_contents($migFile);
                    $pdo->exec($sql);
                    $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$basename]);
                    $migrationResults[] = ['file' => $basename, 'status' => 'ok'];
                } catch (Exception $e) {
                    $migrationResults[] = ['file' => $basename, 'status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }
    } catch (Exception $e) {
        $migrationResults[] = ['status' => 'error', 'message' => 'Migration system error: ' . $e->getMessage()];
    }
}

// Clear update check cache
set_setting('last_update_check', '');

// Log the update
try {
    log_error(
        'CRM Updated: ' . ($preVersion['version'] ?? '?') . ' → ' . ($postVersion['version'] ?? '?'),
        'update',
        $pullOutput
    );
} catch (Exception $e) {}

echo json_encode([
    'success'          => true,
    'message'          => 'CRM updated successfully!',
    'previous_version' => $preVersion['version'] ?? 'unknown',
    'new_version'      => $postVersion['version'] ?? 'unknown',
    'git_output'       => $pullOutput,
    'migrations'       => $migrationResults,
]);
