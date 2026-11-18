<?php
require_once __DIR__ . '/migration_runner.php';

function crmReleaseRevision($value): string
{
    return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) ? $value : '';
}

/** An immutable release has a commit marker written while packaging it. */
function crmInstalledRevision(string $projectDir): string
{
    $path = $projectDir . '/release.json';
    $manifest = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return crmReleaseRevision($manifest['revision'] ?? null);
}

function crmUpdateTarget(string $projectDir): array
{
    $path = $projectDir . '/version.json';
    $metadata = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    $repo = $metadata['github_repo'] ?? '';
    $branch = $metadata['github_branch'] ?? '';
    if (!is_string($repo) || !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo)
        || !is_string($branch) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9_./-]*$#D', $branch)) {
        throw new RuntimeException('Invalid GitHub release target.');
    }

    return [
        'repo' => $repo,
        'branch' => $branch,
        'api' => 'https://api.github.com/repos/' . $repo,
        'workflow' => 'https://github.com/' . $repo . '/actions/workflows/deploy-production.yml',
        'version' => (string)($metadata['version'] ?? ''),
        'build' => (int)($metadata['build'] ?? 0),
    ];
}

function crmMigrationInventory(PDO $pdo, string $projectDir): array
{
    return [
        'files' => array_map('basename', crmMigrationFiles($projectDir)),
        'applied' => crmAppliedMigrationNames($pdo),
    ];
}

function crmReleaseComparison(string $installed, string $target): string
{
    if ($installed === '' || $target === '') {
        return 'unknown';
    }

    return $installed === $target ? 'current' : 'different';
}
