<?php
/**
 * API: Run CRM Update
 *
 * Strategy:
 * 1. Prefer git pull when shell functions are available and the project is a git repo.
 * 2. Fallback to GitHub archive download/extract when shell execution is unavailable.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

class UpdateException extends RuntimeException {
    public string $step;
    public string $output;
    public string $hint;
    public int $statusCode;

    public function __construct(
        string $message,
        string $step = 'update',
        string $output = '',
        string $hint = '',
        int $statusCode = 200
    ) {
        parent::__construct($message);
        $this->step = $step;
        $this->output = $output;
        $this->hint = $hint;
        $this->statusCode = $statusCode;
    }
}

function jsonExit(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function normalizePath(string $path): string {
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function ensureDir(string $path): void {
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new UpdateException(
            'Cannot create temporary directory.',
            'mkdir',
            $path,
            'Grant the web server user write access to the project directory.'
        );
    }
}

function removeTree(string $path, string $allowedBase): void {
    $path = rtrim(normalizePath($path), DIRECTORY_SEPARATOR);
    $allowedBase = rtrim(normalizePath($allowedBase), DIRECTORY_SEPARATOR);

    if ($path === '' || $allowedBase === '' || strpos($path, $allowedBase . DIRECTORY_SEPARATOR) !== 0) {
        throw new UpdateException(
            'Refused to remove an unexpected temporary path.',
            'cleanup_guard',
            $path,
            'Check updater temp path handling before retrying.'
        );
    }

    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removeTree($path . DIRECTORY_SEPARATOR . $item, $allowedBase);
    }

    @rmdir($path);
}

function fetchToFile(string $url, string $targetFile, array $headers = [], int $timeout = 60): array {
    $handle = fopen($targetFile, 'wb');
    if ($handle === false) {
        throw new UpdateException(
            'Cannot create temporary archive file.',
            'temp_file',
            $targetFile,
            'Grant the web server user write access to the temp directory.'
        );
    }

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_FILE           => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Repair-CRM-Updater/1.2',
    ];

    if (!empty($headers)) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($handle);

    if (!$ok || $httpCode < 200 || $httpCode >= 300) {
        @unlink($targetFile);
        return [
            'success'   => false,
            'http_code' => $httpCode,
            'error'     => $error,
        ];
    }

    return [
        'success'   => true,
        'http_code' => $httpCode,
        'error'     => '',
    ];
}

function shellFunctionsAvailable(): bool {
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));

    return (
        (function_exists('exec') && !in_array('exec', $disabled, true)) ||
        (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true))
    );
}

function runCommand(string $command, ?int &$exitCode = null): string {
    $exitCode = null;

    if (function_exists('exec')) {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!in_array('exec', $disabled, true)) {
            $lines = [];
            exec($command . ' 2>&1', $lines, $exitCode);
            return implode("\n", $lines);
        }
    }

    if (function_exists('shell_exec')) {
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        if (!in_array('shell_exec', $disabled, true)) {
            $output = shell_exec($command . ' 2>&1');
            return is_string($output) ? trim($output) : '';
        }
    }

    throw new UpdateException(
        'PHP shell functions are disabled on this server.',
        'shell_disabled',
        '',
        'Use archive-based updating or enable exec/shell_exec for the web server user.'
    );
}

function buildGitCommand(string $projectDir, string $args): string {
    return 'git -C ' . escapeshellarg($projectDir) . ' ' . $args;
}

function copyTree(
    string $sourceDir,
    string $targetDir,
    array $rootExcludes,
    array &$stats,
    array &$warnings,
    bool $isRoot = true
): void {
    $items = scandir($sourceDir);
    if ($items === false) {
        throw new UpdateException(
            'Cannot read extracted update directory.',
            'archive_scan',
            $sourceDir,
            'Check temp directory permissions and retry.'
        );
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        if ($isRoot && in_array($item, $rootExcludes, true)) {
            continue;
        }

        $srcPath = $sourceDir . DIRECTORY_SEPARATOR . $item;
        $dstPath = $targetDir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($srcPath)) {
            if (!is_dir($dstPath) && !mkdir($dstPath, 0775, true) && !is_dir($dstPath)) {
                throw new UpdateException(
                    'Cannot create target directory during update.',
                    'copy_directory',
                    $dstPath,
                    'Grant the web server user write access to the project files.'
                );
            }

            $stats['directories']++;
            copyTree($srcPath, $dstPath, [], $stats, $warnings, false);
            continue;
        }

        if (!@copy($srcPath, $dstPath)) {
            $err = error_get_last();
            $warnings[] = basename($dstPath) . ': ' . ($err['message'] ?? 'copy failed');
            continue;
        }

        $stats['files']++;
    }
}

function findExtractedRoot(string $extractDir): string {
    $items = scandir($extractDir);
    if ($items === false) {
        throw new UpdateException(
            'Cannot inspect extracted archive directory.',
            'archive_root',
            $extractDir,
            'Check temp directory permissions and retry.'
        );
    }

    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $extractDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            $dirs[] = $full;
        }
    }

    if (count($dirs) === 1) {
        return $dirs[0];
    }

    if (file_exists($extractDir . DIRECTORY_SEPARATOR . 'version.json')) {
        return $extractDir;
    }

    throw new UpdateException(
        'Cannot determine extracted project root.',
        'archive_root',
        implode(', ', $dirs),
        'Verify that the downloaded archive is a valid GitHub project snapshot.'
    );
}

function applyArchiveUpdate(string $projectDir, string $repo, string $branch): array {
    if (!function_exists('curl_init')) {
        throw new UpdateException(
            'cURL extension is required for archive-based updates.',
            'archive_requirements',
            '',
            'Enable the PHP cURL extension on the server.'
        );
    }

    if (!class_exists('PharData')) {
        throw new UpdateException(
            'PharData support is required for archive-based updates.',
            'archive_requirements',
            '',
            'Enable the PHP Phar extension on the server.'
        );
    }

    $tempBaseDir = normalizePath($projectDir . DIRECTORY_SEPARATOR . 'temp');
    ensureDir($tempBaseDir);

    $tempUpdateDir = $tempBaseDir . DIRECTORY_SEPARATOR . 'updater_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    ensureDir($tempUpdateDir);

    $archiveGz = $tempUpdateDir . DIRECTORY_SEPARATOR . 'source.tar.gz';
    $archiveTar = $tempUpdateDir . DIRECTORY_SEPARATOR . 'source.tar';
    $extractDir = $tempUpdateDir . DIRECTORY_SEPARATOR . 'extract';
    ensureDir($extractDir);

    $downloadUrls = [
        [
            'url' => "https://codeload.github.com/{$repo}/tar.gz/refs/heads/{$branch}",
            'headers' => [],
        ],
        [
            'url' => "https://api.github.com/repos/{$repo}/tarball/refs/heads/{$branch}",
            'headers' => ['Accept: application/vnd.github.v3+json'],
        ],
    ];

    $downloadErrors = [];
    $usedUrl = '';

    try {
        foreach ($downloadUrls as $candidate) {
            $result = fetchToFile($candidate['url'], $archiveGz, $candidate['headers']);
            if ($result['success']) {
                $usedUrl = $candidate['url'];
                break;
            }

            $downloadErrors[] = $candidate['url'] . ' [HTTP ' . $result['http_code'] . '] ' . $result['error'];
        }

        if ($usedUrl === '') {
            throw new UpdateException(
                'Cannot download update archive from GitHub.',
                'archive_download',
                implode("\n", $downloadErrors),
                'Check outbound HTTPS access from the server to GitHub.'
            );
        }

        if (file_exists($archiveTar)) {
            @unlink($archiveTar);
        }

        try {
            $compressed = new PharData($archiveGz);
            $compressed->decompress();
            $tar = new PharData($archiveTar);
            $tar->extractTo($extractDir, null, true);
        } catch (Throwable $e) {
            throw new UpdateException(
                'Cannot extract downloaded update archive.',
                'archive_extract',
                $e->getMessage(),
                'Check temp directory permissions and PHP Phar support.'
            );
        }

        $sourceRoot = findExtractedRoot($extractDir);
        if (!file_exists($sourceRoot . DIRECTORY_SEPARATOR . 'version.json')) {
            throw new UpdateException(
                'Downloaded archive is missing version.json.',
                'archive_validate',
                $sourceRoot,
                'Verify that the GitHub repository contains a valid CRM release.'
            );
        }

        $stats = ['files' => 0, 'directories' => 0];
        $warnings = [];
        $excludeNames = [
            '.git',
            '.env',
            'uploads',
            'backup_db',
            'temp',
            'memory',
            'facktura',
            'vendor',
        ];

        copyTree($sourceRoot, $projectDir, $excludeNames, $stats, $warnings, true);

        return [
            'method'              => 'archive',
            'archive_url'         => $usedUrl,
            'files_copied'        => $stats['files'],
            'directories_created' => $stats['directories'],
            'warnings'            => $warnings,
        ];
    } finally {
        try {
            removeTree($tempUpdateDir, $tempBaseDir);
        } catch (Throwable $e) {
            // Ignore temp cleanup problems; the main update result is more important.
        }
    }
}

function tableExists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function runMigrations(PDO $pdo, string $projectDir): array {
    $migrationsDir = $projectDir . '/migrations';
    $results = [];

    if (!is_dir($migrationsDir)) {
        return $results;
    }

    $migrationFiles = glob($migrationsDir . '/*.sql');
    sort($migrationFiles);

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $executed = $pdo->query("SELECT filename FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($migrationFiles as $migFile) {
            $basename = basename($migFile);
            if (in_array($basename, $executed, true)) {
                continue;
            }

            if (
                $basename === '001_bootstrap.sql' &&
                tableExists($pdo, 'users') &&
                tableExists($pdo, 'customers') &&
                tableExists($pdo, 'orders')
            ) {
                $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$basename]);
                $results[] = ['file' => $basename, 'status' => 'skipped_existing_schema'];
                continue;
            }

            try {
                $sql = file_get_contents($migFile);
                $pdo->exec($sql);
                $pdo->prepare("INSERT INTO _migrations (filename) VALUES (?)")->execute([$basename]);
                $results[] = ['file' => $basename, 'status' => 'ok'];
            } catch (Throwable $e) {
                $results[] = ['file' => $basename, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
    } catch (Throwable $e) {
        $results[] = ['status' => 'error', 'message' => 'Migration system error: ' . $e->getMessage()];
    }

    return $results;
}

function runGitUpdate(string $projectDir): array {
    $gitCheckCode = null;
    $gitCheck = runCommand('git --version', $gitCheckCode);
    if (($gitCheckCode !== null && $gitCheckCode !== 0) || stripos($gitCheck, 'git version') === false) {
        throw new UpdateException(
            'Git is not installed on this server.',
            'git_check',
            trim($gitCheck),
            'Install git and ensure the web server user can execute it.'
        );
    }

    if (!is_dir($projectDir . '/.git')) {
        throw new UpdateException(
            'Project directory is not a git repository.',
            'git_repo_check',
            $projectDir,
            'Initialize git on the server or use archive-based updates.'
        );
    }

    $statusCode = null;
    $statusOutput = trim(runCommand(buildGitCommand($projectDir, 'status --porcelain'), $statusCode));
    $hasLocalChanges = $statusOutput !== '';
    $stashOutput = '';
    $stashPopOutput = '';
    $didStash = false;

    if ($hasLocalChanges) {
        $stashCode = null;
        $stashOutput = trim(runCommand(
            buildGitCommand($projectDir, 'stash push --include-untracked -m "repair-crm-auto-update"'),
            $stashCode
        ));

        if ($stashCode !== null && $stashCode !== 0) {
            throw new UpdateException(
                'Failed to stash local changes before update.',
                'git_stash',
                $stashOutput,
                'Review local modifications in the repository and resolve them before running the updater.'
            );
        }

        $didStash = stripos($stashOutput, 'No local changes to save') === false;
    }

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
            try {
                runCommand(buildGitCommand($projectDir, 'stash pop'), $unusedCode);
            } catch (Throwable $e) {
            }
        }

        $hint = 'Check git permissions and repository state on the server.';
        if (stripos($pullOutput, 'Permission denied') !== false) {
            $hint = 'Grant the web server user write access to the project directory and .git files.';
        } elseif (stripos($pullOutput, 'dubious ownership') !== false) {
            $hint = 'Mark the repository as safe for the web server user: git config --global --add safe.directory ' . $projectDir;
        } elseif (
            stripos($pullOutput, 'could not read Username') !== false ||
            stripos($pullOutput, 'Authentication failed') !== false
        ) {
            $hint = 'Configure repository authentication for the web server user, or switch origin to a deploy key / token based remote.';
        }

        throw new UpdateException(
            'Git pull failed',
            'git_pull',
            $pullOutput,
            $hint
        );
    }

    if ($didStash) {
        $stashPopCode = null;
        $stashPopOutput = trim(runCommand(buildGitCommand($projectDir, 'stash pop'), $stashPopCode));
        if (
            ($stashPopCode !== null && $stashPopCode !== 0) ||
            stripos($stashPopOutput, 'CONFLICT') !== false ||
            stripos($stashPopOutput, 'error:') !== false
        ) {
            throw new UpdateException(
                'Update was downloaded, but local changes could not be restored cleanly.',
                'git_stash_pop',
                $stashPopOutput,
                'Resolve the stash conflicts manually in the repository and then verify the application state.'
            );
        }
    }

    return [
        'method'           => 'git',
        'git_output'       => $pullOutput,
        'stash_output'     => $stashOutput,
        'stash_pop_output' => $stashPopOutput,
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonExit(['success' => false, 'message' => 'Method not allowed'], 405);
    }

    if (($_SESSION['role'] ?? '') !== 'admin' && !hasPermission('admin_access')) {
        jsonExit(['success' => false, 'message' => __('access_denied')], 403);
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonExit(['success' => false, 'message' => __('csrf_invalid')], 400);
    }

    $projectDir = realpath(__DIR__ . '/..');
    if (!$projectDir) {
        throw new UpdateException(
            'Cannot resolve project directory.',
            'project_dir',
            __DIR__,
            'Check the application deployment path on the server.'
        );
    }

    $versionFile = $projectDir . '/version.json';
    $localVersion = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : [];
    $repo = $localVersion['github_repo'] ?? 'RichTechCZ/Repair-CRM';
    $branch = $localVersion['github_branch'] ?? 'main';
    $requestedMethod = strtolower(trim((string)($_POST['update_method'] ?? '')));

    $preVersion = is_array($localVersion) ? $localVersion : [];
    $updateSummary = [];
    $usedMethod = '';

    if ($requestedMethod === 'archive') {
        $updateSummary = applyArchiveUpdate($projectDir, $repo, $branch);
        $usedMethod = 'archive';
    } elseif (shellFunctionsAvailable() && is_dir($projectDir . '/.git')) {
        $updateSummary = runGitUpdate($projectDir);
        $usedMethod = 'git';
    } else {
        $updateSummary = applyArchiveUpdate($projectDir, $repo, $branch);
        $usedMethod = 'archive';
    }

    $postVersion = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : [];
    $migrationResults = runMigrations($pdo, $projectDir);

    set_setting('last_update_check', '');

    try {
        log_error(
            'CRM Updated: ' . ($preVersion['version'] ?? '?') . ' -> ' . ($postVersion['version'] ?? '?'),
            'update',
            json_encode([
                'method' => $usedMethod,
                'summary' => $updateSummary,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    } catch (Throwable $e) {
    }

    jsonExit([
        'success'          => true,
        'message'          => 'CRM updated successfully!',
        'method'           => $usedMethod,
        'previous_version' => $preVersion['version'] ?? 'unknown',
        'new_version'      => $postVersion['version'] ?? 'unknown',
        'summary'          => $updateSummary,
        'migrations'       => $migrationResults,
    ]);
} catch (UpdateException $e) {
    jsonExit([
        'success' => false,
        'message' => $e->getMessage(),
        'step'    => $e->step,
        'output'  => $e->output,
        'hint'    => $e->hint,
    ], $e->statusCode);
} catch (Throwable $e) {
    jsonExit([
        'success' => false,
        'message' => 'Update process failed unexpectedly.',
        'step'    => 'internal_error',
        'output'  => $e->getMessage(),
        'hint'    => 'Check PHP extensions, outbound HTTPS access, and filesystem permissions for the web server user.',
    ], 500);
}
