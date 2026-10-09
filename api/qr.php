<?php
/**
 * Same-origin QR image (SVG) for authenticated UI, e.g. the phone-number popover.
 * Replaces api.qrserver.com so customer phone numbers never leave the CRM.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/qr_code.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'json' => false,
    'rate' => ['action' => 'qr', 'max' => 300, 'window' => 60],
]);

$text = (string)($_GET['d'] ?? '');
if ($text === '' || strlen($text) > 200) {
    http_response_code(400);
    exit;
}

try {
    $svg = crmQrSvg($text);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: image/svg+xml');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $svg;
