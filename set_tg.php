<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/telegram_webhook_security.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    http_response_code(403);
    echo "Access denied";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><body>';
    echo '<form method="post"><input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token'] ?? '') . '">';
    echo '<button type="submit">Configure Telegram webhook</button></form>';
    echo '</body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(__('csrf_token_invalid'));
}

try {
    $webhookUrl = trim((string)(getenv('TELEGRAM_WEBHOOK_URL') ?: ''));
    $webhookSecret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: ''));

    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '') {
        throw new RuntimeException('Telegram bot token is not configured.');
    }
    if (!isValidTelegramWebhookUrl($webhookUrl)) {
        throw new RuntimeException('TELEGRAM_WEBHOOK_URL must be a valid supported HTTPS URL.');
    }
    if (!isValidTelegramWebhookSecretFormat($webhookSecret)) {
        throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET must be 1-256 URL-safe characters.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required to configure Telegram webhooks.');
    }

    $requestUrl = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/setWebhook';
    $payload = http_build_query([
        'url' => $webhookUrl,
        'secret_token' => $webhookSecret,
        'allowed_updates' => json_encode(['message', 'callback_query']),
    ]);

    $curl = curl_init($requestUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $result = is_string($response) ? json_decode($response, true) : null;
    if ($response === false || !is_array($result) || empty($result['ok'])) {
        error_log('Telegram webhook setup failed: HTTP ' . $httpCode . ' ' . $curlError);
        throw new RuntimeException('Telegram rejected the webhook configuration.');
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'Telegram webhook configured.';
} catch (Throwable $e) {
    error_log('Telegram webhook setup failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Telegram webhook configuration failed.';
}
?>
