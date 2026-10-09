<?php
/**
 * Authorized delivery of order attachments.
 *
 * uploads/ is web-denied: every photo/video is served here after the same order
 * authorization as the order itself (technicians only see their own orders).
 * Supports single HTTP Range requests so videos can be seeked.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/upload_security.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'json' => false,
    'rate' => ['action' => 'media', 'max' => 600, 'window' => 60],
]);

$attachmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$attachmentId) {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare('SELECT order_id, file_path, file_type FROM order_attachments WHERE id = ?');
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
// Same response for "missing" and "forbidden": attachment ids must not be enumerable.
if (!$attachment || !currentUserCanViewOrder((int)$attachment['order_id'])) {
    http_response_code(404);
    exit;
}

$path = crmResolveStoredUploadPath((string)$attachment['file_path']);
if ($path === null) {
    http_response_code(404);
    exit;
}

$policy = crmOrderUploadPolicy();
$mime = (string)$attachment['file_type'];
if (!isset($policy[$mime])) {
    $detected = function_exists('mime_content_type') ? (string)mime_content_type($path) : '';
    $mime = isset($policy[$detected]) ? $detected : 'application/octet-stream';
}

$size = (int)filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', (string)$_SERVER['HTTP_RANGE'], $range)) {
    if ($range[1] === '' && $range[2] !== '') {
        $start = max(0, $size - (int)$range[2]);
    } else {
        $start = (int)$range[1];
        if ($range[2] !== '') {
            $end = min($end, (int)$range[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
session_write_close(); // long video streams must not block the user's other requests

http_response_code($status);
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    exit;
}
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, (int)min(1048576, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    flush();
}
fclose($handle);
