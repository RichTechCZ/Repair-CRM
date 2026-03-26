<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

try {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        throw new Exception("Missing HTTP_HOST");
    }
    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/setWebhook?url=https://" . $host . "/crm/tg_webhook.php";
    $res = file_get_contents($url);
    echo "Result: " . $res;
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
