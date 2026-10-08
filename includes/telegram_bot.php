<?php
/**
 * Telegram Bot Helper Functions & API Transport for Repair CRM
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/upload_security.php';
require_once __DIR__ . '/migration_runner.php';

/**
 * Execute a low-level Telegram Bot API request.
 */
function telegramBotApiRequest(string $method, array $data = []): ?array
{
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '') {
        error_log("Telegram API Error: TG_BOT_TOKEN is not configured.");
        return null;
    }

    if (!function_exists('curl_init')) {
        error_log("Telegram API Error: cURL extension is unavailable.");
        return null;
    }

    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/" . $method;

    $ch = curl_init();
    if ($ch === false) {
        return null;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);

    if ($response === false) {
        error_log("Telegram API cURL error on {$method}: " . $curlError);
        return null;
    }

    $result = json_decode($response, true);
    if (!is_array($result) || !($result['ok'] ?? false)) {
        $desc = $result['description'] ?? "HTTP {$httpCode}";
        error_log("Telegram API error response on {$method}: " . $desc);
    }

    return is_array($result) ? $result : null;
}

/**
 * Send a message with optional reply markup (Inline or Reply keyboard).
 */
function telegramSend($chatId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): ?array
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }

    return telegramBotApiRequest('sendMessage', $payload);
}

/**
 * Edit an existing message text and keyboard.
 */
function telegramEditMessageText($chatId, int $messageId, string $text, ?array $replyMarkup = null, string $parseMode = 'HTML'): ?array
{
    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }

    return telegramBotApiRequest('editMessageText', $payload);
}

/**
 * Answer a callback query from inline buttons.
 */
function telegramAnswerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
{
    $payload = ['callback_query_id' => $callbackQueryId];
    if ($text !== null && $text !== '') {
        $payload['text'] = $text;
        $payload['show_alert'] = $showAlert;
    }

    $res = telegramBotApiRequest('answerCallbackQuery', $payload);
    return !empty($res['ok']);
}

/**
 * Get file information from Telegram.
 */
function telegramGetFile(string $fileId): ?array
{
    $res = telegramBotApiRequest('getFile', ['file_id' => $fileId]);
    if (!empty($res['ok']) && !empty($res['result'])) {
        return $res['result'];
    }
    return null;
}

/**
 * Download a file from Telegram servers into memory/disk.
 */
function telegramDownloadFile(string $filePath): ?string
{
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '' || !function_exists('curl_init')) {
        return null;
    }

    $url = "https://api.telegram.org/file/bot" . TG_BOT_TOKEN . "/" . $filePath;

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if ($content === false || $httpCode !== 200) {
        error_log("Failed to download Telegram file from {$filePath}, HTTP: {$httpCode}");
        return null;
    }

    return $content;
}

/**
 * Download and securely store media from Telegram as an order attachment.
 */
function telegramSaveMediaAttachment(PDO $pdo, int $orderId, string $fileId, ?string $originalClientName = null): array
{
    $fileInfo = telegramGetFile($fileId);
    if (!$fileInfo || empty($fileInfo['file_path'])) {
        return ['success' => false, 'message' => 'Не удалось получить информацию о файле от Telegram.'];
    }

    $fileContent = telegramDownloadFile($fileInfo['file_path']);
    if ($fileContent === null || strlen($fileContent) === 0) {
        return ['success' => false, 'message' => 'Не удалось загрузить файл с серверов Telegram.'];
    }

    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
    crmEnsureUploadDirectory($uploadDirectory);

    $tempPath = tempnam(sys_get_temp_dir(), 'tg_media_');
    if ($tempPath === false || file_put_contents($tempPath, $fileContent) === false) {
        if ($tempPath && is_file($tempPath)) @unlink($tempPath);
        return ['success' => false, 'message' => 'Ошибка временного сохранения файла.'];
    }

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string)$finfo->file($tempPath);
        $policy = crmOrderUploadPolicy();
        $mimePolicy = $policy[$mimeType] ?? null;

        if ($mimePolicy === null) {
            @unlink($tempPath);
            return ['success' => false, 'message' => "Неподдерживаемый тип файла ({$mimeType}). Разрешены только фото и видео."];
        }

        $fileSize = filesize($tempPath);
        if ($fileSize > $mimePolicy['max_bytes']) {
            @unlink($tempPath);
            return ['success' => false, 'message' => 'Размер файла превышает допустимый лимит.'];
        }

        if (str_starts_with($mimeType, 'image/') && @getimagesize($tempPath) === false) {
            @unlink($tempPath);
            return ['success' => false, 'message' => 'Файл изображения поврежден или имеет неверный формат.'];
        }

        $storedName = bin2hex(random_bytes(24)) . '.' . $mimePolicy['extension'];
        $absolutePath = $uploadDirectory . $storedName;

        if (!rename($tempPath, $absolutePath) && !copy($tempPath, $absolutePath)) {
            @unlink($tempPath);
            return ['success' => false, 'message' => 'Не удалось сохранить файл в хранилище.'];
        }
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }
        @chmod($absolutePath, 0644);

        $fileName = $originalClientName ?: basename($fileInfo['file_path']);
        $fileName = preg_replace('/[^\p{L}\p{N}\._\- ]/u', '_', $fileName);
        if (trim($fileName) === '') {
            $fileName = 'telegram_' . date('Ymd_His') . '.' . $mimePolicy['extension'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, 'uploads/' . $storedName, $mimeType, mb_substr($fileName, 0, 255)]);

        return [
            'success' => true,
            'attachment_id' => (int)$pdo->lastInsertId(),
            'file_name' => $fileName,
            'file_type' => $mimeType,
        ];
    } catch (Throwable $e) {
        if (is_file($tempPath)) @unlink($tempPath);
        error_log("telegramSaveMediaAttachment error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Внутренняя ошибка сохранения: ' . $e->getMessage()];
    }
}

/**
 * State Management (FSM) Helpers
 */
function telegramEnsureStateTable(PDO $pdo): void
{
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS telegram_bot_states (
                telegram_id VARCHAR(50) PRIMARY KEY,
                state VARCHAR(50) NOT NULL,
                order_id INTEGER DEFAULT NULL,
                temp_data TEXT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `telegram_bot_states` (
            `telegram_id` VARCHAR(50) NOT NULL,
            `state`       VARCHAR(50) NOT NULL,
            `order_id`    INT(11)     DEFAULT NULL,
            `temp_data`   TEXT        DEFAULT NULL,
            `updated_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`telegram_id`),
            KEY `idx_tg_state_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function telegramGetState(PDO $pdo, string $telegramId): ?array
{
    telegramEnsureStateTable($pdo);
    $stmt = $pdo->prepare('SELECT state, order_id, temp_data FROM telegram_bot_states WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['state'])) {
        return null;
    }

    $tempData = null;
    if (!empty($row['temp_data'])) {
        $tempData = json_decode($row['temp_data'], true);
    }

    return [
        'state' => (string)$row['state'],
        'order_id' => $row['order_id'] !== null ? (int)$row['order_id'] : null,
        'temp_data' => is_array($tempData) ? $tempData : [],
    ];
}

function telegramSetState(PDO $pdo, string $telegramId, string $state, ?int $orderId = null, ?array $tempData = null): void
{
    telegramEnsureStateTable($pdo);
    $json = $tempData !== null ? json_encode($tempData, JSON_UNESCAPED_UNICODE) : null;
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare(
            "INSERT INTO telegram_bot_states (telegram_id, state, order_id, temp_data, updated_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT(telegram_id) DO UPDATE SET
                 state = excluded.state,
                 order_id = excluded.order_id,
                 temp_data = excluded.temp_data,
                 updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$telegramId, $state, $orderId, $json]);
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO telegram_bot_states (telegram_id, state, order_id, temp_data)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE state = VALUES(state), order_id = VALUES(order_id), temp_data = VALUES(temp_data), updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$telegramId, $state, $orderId, $json]);
}

function telegramClearState(PDO $pdo, string $telegramId): void
{
    telegramEnsureStateTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM telegram_bot_states WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
}

/**
 * Resolve user profile and scope by Telegram ID / username.
 */
function telegramResolveUser(PDO $pdo, $fromId, ?string $username = null): ?array
{
    $fromIdStr = (string)$fromId;
    $usernameClean = trim(ltrim((string)$username, '@'));
    $usernameWithAt = "@" . $usernameClean;

    // Check system setting for admin chat ID (e.g. 2427615)
    $adminChatId = getStatusChangeAdminTelegramId();
    $isDirectConfigAdmin = ($adminChatId !== '' && $fromIdStr === $adminChatId);

    // 1. Check technicians table
    $stmt = $pdo->prepare(
        "SELECT t.*, 
                GROUP_CONCAT(tp.permission) AS permissions
         FROM technicians t
         LEFT JOIN tech_permissions tp ON t.id = tp.technician_id
         WHERE (t.telegram_id = ? OR t.telegram_id = ?) AND t.is_active = 1
         GROUP BY t.id
         LIMIT 1"
    );
    $stmt->execute([$fromIdStr, $usernameWithAt]);
    $tech = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tech) {
        $permissions = explode(',', (string)($tech['permissions'] ?? ''));
        $hasAdminAccess = in_array('admin_access', $permissions, true) || $isDirectConfigAdmin;

        return [
            'type' => 'technician',
            'id' => (int)$tech['id'],
            'technician_id' => (int)$tech['id'],
            'telegram_id' => $fromIdStr,
            'name' => (string)$tech['name'],
            'username' => (string)($tech['username'] ?? ''),
            'is_admin' => $hasAdminAccess,
            'rate' => (float)($tech['engineer_rate'] ?? 50.0),
        ];
    }

    // 2. Check users table (CRM administrators)
    if (crmMigrationTableExists($pdo, 'users')) {
        $hasCol = crmMigrationColumnExists($pdo, 'users', 'telegram_id');
        if ($hasCol) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ? LIMIT 1");
            $stmt->execute([$fromIdStr]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return [
                    'type' => 'admin',
                    'id' => (int)$user['id'],
                    'technician_id' => null,
                    'telegram_id' => $fromIdStr,
                    'name' => (string)($user['full_name'] ?: $user['username']),
                    'username' => (string)$user['username'],
                    'is_admin' => true,
                    'rate' => 0.0,
                ];
            }
        }
    }

    // 3. Fallback: if matches status change admin telegram ID
    if ($isDirectConfigAdmin) {
        return [
            'type' => 'admin',
            'id' => 0,
            'technician_id' => null,
            'telegram_id' => $fromIdStr,
            'name' => 'CRM Администратор',
            'username' => 'admin',
            'is_admin' => true,
            'rate' => 0.0,
        ];
    }

    return null;
}

/**
 * Check if the resolved Telegram user has access to a specific order.
 */
function telegramCanAccessOrder(PDO $pdo, array $user, int $orderId): bool
{
    if (!empty($user['is_admin'])) {
        return true;
    }

    if ($user['type'] === 'technician' && !empty($user['technician_id'])) {
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE id = ? AND technician_id = ?');
        $stmt->execute([$orderId, $user['technician_id']]);
        return (bool)$stmt->fetchColumn();
    }

    return false;
}
