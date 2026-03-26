<?php
/**
 * Telegram Bot Webhook Handler for Repair CRM
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$message = $update['message'] ?? null;
if (!$message) exit;

$chatId = $message['chat']['id'];
$text = $message['text'] ?? '';
$fromId = $message['from']['id'];

$stmt = $pdo->prepare("SELECT id, name FROM technicians WHERE (telegram_id = ? OR telegram_id = ?) AND is_active = 1");
$stmt->execute([$fromId, "@" . ($message['from']['username'] ?? '---')]);
$tech = $stmt->fetch();

if (!$tech) {
    $username = isset($message['from']['username']) ? "@" . $message['from']['username'] : "без никнейма";
    $msg = "❌ Вы не зарегистрированы в CRM.\n\n";
    $msg .= "Ваш цифровой ID: <code>$fromId</code>\n";
    $msg .= "Ваш никнейм: <code>$username</code>\n\n";
    $msg .= "Попросите администратора добавить ваш ID в настройки вашего профиля.";
    sendTelegramNotification($chatId, $msg);
    exit;
}

if ($text == '/start' || $text == '/help') {
    $msg = "👋 Привет, <b>{$tech['name']}</b>!\n\n";
    $msg .= "Ваш цифровой ID: <code>$fromId</code> (скопируйте его в настройки CRM)\n\n";
    $msg .= "Команды:\n";
    $msg .= "📂 /my - Мои активные заявки\n";
    $msg .= "🔍 /view [ID] - Детали заявки\n";
    sendTelegramNotification($chatId, $msg);
    exit;
}

if ($text == '/my') {
    $stmt = $pdo->prepare("SELECT id, device_brand, device_model, status FROM orders WHERE technician_id = ? AND status NOT IN ('Collected', 'Cancelled') ORDER BY created_at DESC");
    $stmt->execute([$tech['id']]);
    $orders = $stmt->fetchAll();
    
    if (empty($orders)) {
        sendTelegramNotification($chatId, "✅ У вас нет активных заявок.");
    } else {
        $msg = "📂 <b>Ваши активные заявки:</b>\n\n";
        foreach ($orders as $o) {
            $msg .= "#{$o['id']} - {$o['device_brand']} {$o['device_model']} [{$o['status']}]\n";
        }
        sendTelegramNotification($chatId, $msg);
    }
    exit;
}

if (preg_match('/^\/view (\d+)$/', $text, $matches)) {
    $orderId = $matches[1];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ? AND o.technician_id = ?");
    $stmt->execute([$orderId, $tech['id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        sendTelegramNotification($chatId, "❌ Заявка #$orderId не найдена или назначена не вам.");
    } else {
        $msg = "📑 <b>Заявка #{$order['id']}</b>\n";
        $msg .= "👤 Клиент: {$order['first_name']} {$order['last_name']} ({$order['phone']})\n";
        $msg .= "📱 Устройство: {$order['device_brand']} {$order['device_model']}\n";
        $msg .= "📝 Проблема: {$order['problem_description']}\n";
        $msg .= "📍 Статус: {$order['status']}\n";
        sendTelegramNotification($chatId, $msg);
    }
    exit;
}
?>
