<?php
/**
 * Telegram Bot Webhook Handler for Repair CRM
 */
require_once __DIR__ . '/includes/env_loader.php';
require_once __DIR__ . '/includes/telegram_webhook_security.php';
loadEnv(__DIR__ . '/.env');

$webhookSecret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: ''));
$providedSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!isValidTelegramWebhookSecret($webhookSecret, $providedSecret)) {
    // Fail closed: a request without the configured Telegram secret is never a webhook update.
    http_response_code($webhookSecret === '' ? 503 : 403);
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/telegram_bot.php';
require_once __DIR__ . '/models/TelegramBotRouter.php';

$content = file_get_contents("php://input");
if (!$content) {
    exit;
}

$update = json_decode($content, true);
if (!is_array($update)) {
    exit;
}

try {
    TelegramBotRouter::handleUpdate($pdo, $update);
} catch (Throwable $e) {
    error_log("Telegram webhook error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
}
?>
