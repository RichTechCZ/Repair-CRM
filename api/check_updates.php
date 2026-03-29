<?php
/**
 * API: Check for CRM Updates from GitHub
 * Compares local version.json with the one on GitHub (main branch)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins can check for updates
if (($_SESSION['role'] ?? '') !== 'admin' && !hasPermission('admin_access')) {
    echo json_encode(['success' => false, 'message' => __('access_denied')]);
    exit;
}

// Read local version
$localVersionFile = __DIR__ . '/../version.json';
if (!file_exists($localVersionFile)) {
    echo json_encode(['success' => false, 'message' => 'version.json not found']);
    exit;
}

$localVersion = json_decode(file_get_contents($localVersionFile), true);
if (!$localVersion) {
    echo json_encode(['success' => false, 'message' => 'Invalid version.json']);
    exit;
}

$repo   = $localVersion['github_repo'] ?? 'RichTechCZ/Repair-CRM';
$branch = $localVersion['github_branch'] ?? 'main';
$projectDir = realpath(__DIR__ . '/..');

function fetchRemoteUrl(string $url, array $headers = [], int $timeout = 15): array {
    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Repair-CRM-Updater/1.1',
        CURLOPT_FOLLOWLOCATION => true,
    ];

    if (!empty($headers)) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [$response, $httpCode, $curlError];
}

function getLocalGitHead(string $projectDir): string {
    if (!$projectDir || !is_dir($projectDir . '/.git')) {
        return '';
    }

    $output = shell_exec('git -C ' . escapeshellarg($projectDir) . ' rev-parse HEAD 2>&1');
    $head = trim((string)$output);

    return preg_match('/^[a-f0-9]{40}$/i', $head) ? strtolower($head) : '';
}

// Cache: don't check more than once per 30 minutes
$cacheKey = 'last_update_check';
$cacheData = get_setting($cacheKey, '');
if ($cacheData) {
    $cached = json_decode($cacheData, true);
    if ($cached && isset($cached['checked_at'])) {
        $age = time() - $cached['checked_at'];
        if ($age < 1800 && !isset($_GET['force'])) {
            // Return cached result
            $cached['from_cache'] = true;
            $cached['cache_age'] = $age;
            echo json_encode($cached);
            exit;
        }
    }
}

// Fetch version.json from GitHub (raw content)
$githubUrl = "https://raw.githubusercontent.com/{$repo}/{$branch}/version.json";
$contentsApiUrl = "https://api.github.com/repos/{$repo}/contents/version.json?ref={$branch}";
[$response, $httpCode, $curlError] = fetchRemoteUrl($githubUrl);

$remoteVersion = $response ? json_decode($response, true) : null;
$versionSource = 'raw';
$fallbackHttpCode = 0;
$fallbackCurlError = '';

if ($httpCode !== 200 || !$remoteVersion || !isset($remoteVersion['version'])) {
    [$contentsResponse, $contentsCode, $contentsError] = fetchRemoteUrl(
        $contentsApiUrl,
        ['Accept: application/vnd.github.v3+json']
    );
    $fallbackHttpCode = $contentsCode;
    $fallbackCurlError = $contentsError;

    $contentsPayload = $contentsResponse ? json_decode($contentsResponse, true) : null;
    if (
        $contentsCode === 200 &&
        is_array($contentsPayload) &&
        !empty($contentsPayload['content'])
    ) {
        $decodedContent = base64_decode((string)$contentsPayload['content'], true);
        $decodedVersion = $decodedContent ? json_decode($decodedContent, true) : null;
        if ($decodedVersion && isset($decodedVersion['version'])) {
            $remoteVersion = $decodedVersion;
            $versionSource = 'contents_api';
            $httpCode = $contentsCode;
            $curlError = $contentsError;
        }
    }
}

if (!$remoteVersion || !isset($remoteVersion['version'])) {
    echo json_encode([
        'success'      => false,
        'message'      => 'Cannot load remote version.json from GitHub',
        'local'        => $localVersion,
        'github_url'   => $githubUrl,
        'http_code'    => $httpCode,
        'curl_error'   => $curlError,
        'fallback_url' => $contentsApiUrl,
        'fallback_http_code' => $fallbackHttpCode,
        'fallback_curl_error' => $fallbackCurlError,
    ]);
    exit;
}

// Compare versions
$localVersionString = (string)($localVersion['version'] ?? '0.0.0');
$remoteVersionString = (string)$remoteVersion['version'];
$localBuild = (int)($localVersion['build'] ?? 0);
$remoteBuild = (int)($remoteVersion['build'] ?? 0);
$localHeadSha = getLocalGitHead($projectDir);
$remoteHeadSha = '';

$versionUpdate = version_compare($remoteVersionString, $localVersionString, '>');
$buildUpdate = !$versionUpdate
    && version_compare($remoteVersionString, $localVersionString, '==')
    && $remoteBuild > $localBuild;

// Fetch latest commits for changelog
$commitsUrl = "https://api.github.com/repos/{$repo}/commits?sha={$branch}&per_page=10";
[$commitsResponse, $commitsCode, $commitsError] = fetchRemoteUrl(
    $commitsUrl,
    ['Accept: application/vnd.github.v3+json'],
    10
);

$changelog = [];
if ($commitsCode === 200 && $commitsResponse) {
    $commits = json_decode($commitsResponse, true);
    if (is_array($commits)) {
        foreach ($commits as $commit) {
            if ($remoteHeadSha === '' && !empty($commit['sha'])) {
                $remoteHeadSha = strtolower((string)$commit['sha']);
            }
            $changelog[] = [
                'sha'     => substr($commit['sha'] ?? '', 0, 7),
                'message' => $commit['commit']['message'] ?? '',
                'date'    => $commit['commit']['committer']['date'] ?? '',
                'author'  => $commit['commit']['author']['name'] ?? '',
            ];
        }
    }
}

$commitUpdate = !$versionUpdate
    && !$buildUpdate
    && $localHeadSha !== ''
    && $remoteHeadSha !== ''
    && $localHeadSha !== $remoteHeadSha;

$hasUpdate = $versionUpdate || $buildUpdate || $commitUpdate;
$updateReason = $versionUpdate ? 'version' : ($buildUpdate ? 'build' : ($commitUpdate ? 'commit' : 'none'));

$result = [
    'success'        => true,
    'has_update'     => $hasUpdate,
    'update_reason'  => $updateReason,
    'local_version'  => $localVersionString,
    'remote_version' => $remoteVersionString,
    'local_build'    => $localBuild,
    'remote_build'   => $remoteBuild,
    'local_commit'   => $localHeadSha ? substr($localHeadSha, 0, 7) : '',
    'remote_commit'  => $remoteHeadSha ? substr($remoteHeadSha, 0, 7) : '',
    'release_date'   => $remoteVersion['release_date'] ?? '',
    'changelog'      => $changelog,
    'checked_at'     => time(),
    'from_cache'     => false,
    'source'         => $versionSource,
    'commits_status' => $commitsCode === 200 ? 'ok' : 'error',
    'commits_error'  => $commitsCode === 200 ? '' : $commitsError,
];

// Cache the result
set_setting($cacheKey, json_encode($result));

echo json_encode($result);
