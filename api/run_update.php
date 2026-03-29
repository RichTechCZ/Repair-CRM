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

function jsonExit(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function runCommand(string $command, ?int &$exitCode = null): string {
    $exitCode = null;

    if (function_exists('exec')) {
        $lines = [];
        exec($command . ' 2>&1', $lines, $exitCode);
        return implode("\n", $lines);
    }

    if (function_exists('shell_exec')) {
        $output = shell_exec($command . ' 2>&1');
        return is_string($output) ? trim($output) : '';
    }

    throw new RuntimeException('PHP shell functions are disabled on this server.');
}

function buildGitCommand(string $projectDir, string $args): string {
    return 'git -C ' . escapeshellarg($projectDir) . ' ' . $args;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonExit(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    // Only admins
    if (($_SESSION['role'] ?? '') !== 'admin' && !hasPermission('admin_access')) {
        jsonExit(['success' => false, 'message' => __('access_denied')], 403);
    }

    // CSRF check
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonExit(['success' => false, 'message' => __('csrf_invalid')], 400);
    }

    $projectDir = realpath(__DIR__ . '/..');
    if (!$projectDir) {
        throw new RuntimeException('Cannot resolve project directory.');
    }

    // Check if git is available
    $gitCheckCode = null;
    $gitCheck = runCommand('git --version', $gitCheckCode);
    if (($gitCheckCode !== null && $gitCheckCode !== 0) || stripos($gitCheck, 'git version') === false) {
        jsonExit([
            'success' => false,
            'message' => 'Git is not installed on this server.',
            'step'    => 'git_check',
            'output'  => trim($gitCheck),
            'hint'    => 'Install git and ensure the web server user can execute it.',
        ]);
    }

    // Check if this is a git repo
    if (!is_dir($projectDir . '/.git')) {
        jsonExit([
            'success' => false,
            'message' => 'Project directory is not a git repository. Please initialize git on the server first.',
            'step'    => 'git_repo_check',
            'hint'    => 'Run: cd ' . $projectDir . ' && git init && git remote add origin https://github.com/RichTechCZ/Repair-CRM.git && git fetch && git checkout main',
        ]);
    }

    // Save pre-update version
    $preVersionFile = $projectDir . '/version.json';
    $preVersion = file_exists($preVersionFile) ? json_decode(file_get_contents($preVersionFile), true) : null;

    // Detect dirty worktree and stash only when needed
    $statusCode = null;
    $statusOutput = trim(runCommand(buildGitCommand($projectDir, 'status --porcelain'), $statusCode));
    $hasLocalChanges = $statusOutput !== '';
    $stashOutput = '';
    $didStash = false;

    if ($hasLocalChanges) {
        $stashCode = null;
        $stashOutput = trim(runCommand(
            buildGitCommand($projectDir, 'stash push --include-untracked -m "repair-crm-auto-update"'),
            $stashCode
        ));
        if ($stashCode !== null && $stashCode !== 0) {
            jsonExit([
                'success' => false,
                'message' => 'Failed to stash local changes before update.',
                'step'    => 'git_stash',
                'output'  => $stashOutput,
                'hint'    => 'Review local modifications in the repository and resolve them before running the updater.',
            ]);
        }
        $didStash = stripos($stashOutput, 'No local changes to save') === false;
    }

    // Pull latest from GitHub
    $pullCode = null;
    $pullOutput = trim(runCommand(buildGitCommand($projectDir, 'pull --ff-only origin main'), $pullCode));

    $isError = (
        ($pullCode !== null && $pullCode !== 0) ||
        stripos($pullOutput, 'fatal:') !== false ||
        stripos($pullOutput, 'error:') !== false ||
        stripos($pullOutput, 'CONFLICT') !== false
    );

    if ($isError) {
        if ($didStash) {
            runCommand(buildGitCommand($projectDir, 'stash pop'), $unusedCode);
        }

        $hint = 'Check git permissions and repository state on the server.';
        if (stripos($pullOutput, 'Permission denied') !== false) {
            $hint = 'Grant the web server user write access to the project directory and .git files.';
        } elseif (stripos($pullOutput, 'dubious ownership') !== false) {
            $hint = 'Mark the repository as safe for the web server user: git config --global --add safe.directory ' . $projectDir;
        } elseif (stripos($pullOutput, 'could not read Username') !== false || stripos($pullOutput, 'Authentication failed') !== false) {
            $hint = 'Configure repository authentication for the web server user, or switch origin to a deploy key / token based remote.';
        }

        jsonExit([
            'success' => false,
            'message' => 'Git pull failed',
            'output'  => $pullOutput,
            'step'    => 'git_pull',
            'hint'    => $hint,
        ]);
    }

    $stashPopOutput = '';
    if ($didStash) {
        $stashPopCode = null;
        $stashPopOutput = trim(runCommand(buildGitCommand($projectDir, 'stash pop'), $stashPopCode));
        if (
            ($stashPopCode !== null && $stashPopCode !== 0) ||
            stripos($stashPopOutput, 'CONFLICT') !== false ||
            stripos($stashPopOutput, 'error:') !== false
        ) {
            jsonExit([
                'success' => false,
                'message' => 'Update was downloaded, but local changes could not be restored cleanly.',
                'step'    => 'git_stash_pop',
                'output'  => $stashPopOutput,
                'hint'    => 'Resolve the stash conflicts manually in the repository and then verify the application state.',
            ]);
        }
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
                if (!in_array($basename, $executed, true)) {
                    try {
                        $sql = file_get_contents($migFile);
                        $pdo->exec($sql);
                        $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$basename]);
                        $migrationResults[] = ['file' => $basename, 'status' => 'ok'];
                    } catch (Throwable $e) {
                        $migrationResults[] = ['file' => $basename, 'status' => 'error', 'message' => $e->getMessage()];
                    }
                }
            }
        } catch (Throwable $e) {
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
    } catch (Throwable $e) {}

    jsonExit([
        'success'          => true,
        'message'          => 'CRM updated successfully!',
        'previous_version' => $preVersion['version'] ?? 'unknown',
        'new_version'      => $postVersion['version'] ?? 'unknown',
        'git_output'       => $pullOutput,
        'stash_output'     => $stashOutput,
        'stash_pop_output' => $stashPopOutput,
        'migrations'       => $migrationResults,
    ]);
} catch (Throwable $e) {
    jsonExit([
        'success' => false,
        'message' => 'Update process failed unexpectedly.',
        'step'    => 'internal_error',
        'output'  => $e->getMessage(),
        'hint'    => 'Check PHP disabled functions, git availability, and filesystem permissions for the web server user.',
    ], 500);
}
