<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/release_info.php';

api_bootstrap(['post' => false, 'permission' => 'admin_access']);
checkApiRateLimit('check_updates', 30, 60);

function crmGitHubJson(string $url): ?array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'Repair-CRM-Updater',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($status !== 200 || !is_string($body)) {
        error_log('CRM update check: GitHub HTTP ' . $status . ' ' . curl_error($handle));
        return null;
    }

    $value = json_decode($body, true);
    return is_array($value) ? $value : null;
}

try {
    $projectDir = dirname(__DIR__);
    $target = crmUpdateTarget($projectDir);
    $branch = rawurlencode($target['branch']);
    $cacheKey = 'github_release_check_v2';
    $cache = json_decode((string)get_setting($cacheKey, ''), true);
    $maxAge = isset($_GET['force']) ? 60 : 300;
    $now = time();
    $age = $now - (int)($cache['checked_at'] ?? 0);
    $cached = $age >= 0 && $age < $maxAge
        && ($cache['repo'] ?? null) === $target['repo']
        && ($cache['branch'] ?? null) === $target['branch'];

    if (!$cached) {
        $commits = crmGitHubJson($target['api'] . '/commits?sha=' . $branch . '&per_page=8');
        $revision = crmReleaseRevision($commits[0]['sha'] ?? null);
        $cache = [
            'repo' => $target['repo'],
            'branch' => $target['branch'],
            'checked_at' => $now,
            'remote_revision' => $revision,
            'remote_version' => '',
            'remote_build' => null,
            'changelog' => [],
            'deployment' => null,
            'remote_migrations' => null,
        ];

        if ($revision !== '') {
            $version = crmGitHubJson('https://raw.githubusercontent.com/' . $target['repo'] . '/' . $revision . '/version.json');
            $cache['remote_version'] = (string)($version['version'] ?? '');
            $cache['remote_build'] = isset($version['build']) ? (int)$version['build'] : null;
            foreach ($commits as $commit) {
                $cache['changelog'][] = [
                    'sha' => substr(crmReleaseRevision($commit['sha'] ?? null), 0, 7),
                    'message' => substr((string)($commit['commit']['message'] ?? ''), 0, 350),
                ];
            }

            $files = crmGitHubJson($target['api'] . '/contents/migrations?ref=' . $revision);
            if ($files !== null && array_is_list($files)) {
                $cache['remote_migrations'] = [];
                foreach ($files as $file) {
                    if (($file['type'] ?? '') === 'file' && crmIsMigrationFilename((string)($file['name'] ?? ''))) {
                        $cache['remote_migrations'][] = $file['name'];
                    }
                }
                sort($cache['remote_migrations'], SORT_STRING);
            }

            $runs = crmGitHubJson($target['api'] . '/actions/workflows/deploy-production.yml/runs?branch=' . $branch . '&per_page=1');
            $run = $runs['workflow_runs'][0] ?? null;
            if (is_array($run) && (int)($run['id'] ?? 0) > 0) {
                $cache['deployment'] = [
                    'id' => (int)$run['id'],
                    'revision' => crmReleaseRevision($run['head_sha'] ?? null),
                    'status' => (string)($run['status'] ?? ''),
                    'conclusion' => (string)($run['conclusion'] ?? ''),
                ];
            }
        }

        // Cache remote data only. A new release or migration is reflected immediately on reload.
        set_setting($cacheKey, json_encode($cache, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    $installed = crmInstalledRevision($projectDir);
    $revision = crmReleaseRevision($cache['remote_revision'] ?? null);
    $deployment = $cache['deployment'];
    if ($deployment !== null) {
        $deployment['url'] = 'https://github.com/' . $target['repo'] . '/actions/runs/' . (int)$deployment['id'];
    }

    $pending = null;
    try {
        if (is_array($cache['remote_migrations'])) {
            $pending = array_values(array_diff($cache['remote_migrations'], crmAppliedMigrationNames($pdo)));
        }
    } catch (Throwable $error) {
        error_log('CRM update check: migration registry unavailable.');
    }

    api_json_exit([
        'success' => $revision !== '',
        'comparison' => crmReleaseComparison($installed, $revision),
        'remote_version' => $cache['remote_version'],
        'remote_build' => $cache['remote_build'],
        'remote_revision' => $revision,
        'changelog' => $cache['changelog'],
        'deployment' => $deployment,
        'pending_migrations' => $pending,
    ]);
} catch (Throwable $error) {
    error_log('CRM update check failed: ' . $error->getMessage());
    api_json_exit(['success' => false, 'message' => __('update_error')], 502);
}
