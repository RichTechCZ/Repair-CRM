<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'json' => false,
    'permission' => 'admin_access',
    'rate' => ['action' => 'catalog_image', 'max' => 300, 'window' => 60],
]);

$file = trim((string)($_GET['f'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif)$/', $file, $matches)) {
    http_response_code(404);
    exit;
}

$catalogRoot = realpath(__DIR__ . '/../uploads/catalog');
if ($catalogRoot === false) {
    http_response_code(404);
    exit;
}

$target = realpath($catalogRoot . DIRECTORY_SEPARATOR . $file);
if (
    $target === false
    || strpos($target, $catalogRoot . DIRECTORY_SEPARATOR) !== 0
    || !is_file($target)
) {
    http_response_code(404);
    exit;
}

$mimeMap = [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
];
$mime = $mimeMap[$matches[1]] ?? 'application/octet-stream';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($target));
header('Content-Disposition: inline; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($target);
exit;
