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

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $githubUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Repair-CRM-Updater/1.0',
    CURLOPT_FOLLOWLOCATION => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Cannot connect to GitHub (HTTP ' . $httpCode . ')',
        'local'    => $localVersion,
    ]);
    exit;
}

$remoteVersion = json_decode($response, true);
if (!$remoteVersion || !isset($remoteVersion['version'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid remote version.json',
        'local'   => $localVersion,
    ]);
    exit;
}

// Compare versions
$hasUpdate = version_compare($remoteVersion['version'], $localVersion['version'], '>');

// Fetch latest commits for changelog
$commitsUrl = "https://api.github.com/repos/{$repo}/commits?sha={$branch}&per_page=10";
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $commitsUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Repair-CRM-Updater/1.0',
    CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
]);
$commitsResponse = curl_exec($ch);
$commitsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$changelog = [];
if ($commitsCode === 200 && $commitsResponse) {
    $commits = json_decode($commitsResponse, true);
    if (is_array($commits)) {
        foreach ($commits as $commit) {
            $changelog[] = [
                'sha'     => substr($commit['sha'] ?? '', 0, 7),
                'message' => $commit['commit']['message'] ?? '',
                'date'    => $commit['commit']['committer']['date'] ?? '',
                'author'  => $commit['commit']['author']['name'] ?? '',
            ];
        }
    }
}

$result = [
    'success'        => true,
    'has_update'     => $hasUpdate,
    'local_version'  => $localVersion['version'],
    'remote_version' => $remoteVersion['version'],
    'local_build'    => $localVersion['build'] ?? 0,
    'remote_build'   => $remoteVersion['build'] ?? 0,
    'release_date'   => $remoteVersion['release_date'] ?? '',
    'changelog'      => $changelog,
    'checked_at'     => time(),
    'from_cache'     => false,
];

// Cache the result
set_setting($cacheKey, json_encode($result));

echo json_encode($result);
