<?php
/**
 * Telegram Bot Router & Controller for Repair CRM
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/telegram_bot.php';
require_once __DIR__ . '/../includes/reports_stats.php';
require_once __DIR__ . '/../includes/migration_runner.php';
require_once __DIR__ . '/OrderStatusService.php';

final class TelegramBotRouter
{
    /**
     * Main entry point for processing incoming Telegram Webhook updates.
     */
    public static function handleUpdate(PDO $pdo, array $update): void
    {
        if (isset($update['callback_query'])) {
            self::handleCallbackQuery($pdo, $update['callback_query']);
            return;
        }

        if (isset($update['message'])) {
            self::handleMessage($pdo, $update['message']);
            return;
        }
    }

    /**
     * Handle incoming messages (Text, Commands, Photos, Videos, Documents).
     */
    private static function handleMessage(PDO $pdo, array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $username = $message['from']['username'] ?? null;
        $text = trim((string)($message['text'] ?? ($message['caption'] ?? '')));

        if (!$chatId || !$fromId) {
            return;
        }

        $user = telegramResolveUser($pdo, $fromId, $username);
        if (!$user) {
            self::sendUnregisteredUserMessage($chatId, $fromId, $username);
            return;
        }

        // Check user active FSM state
        $userState = telegramGetState($pdo, (string)$fromId);

        // Cancel command anytime
        if ($text === '/cancel' || $text === '❌ Отмена') {
            telegramClearState($pdo, (string)$fromId);
            telegramSend($chatId, "👌 Действие отменено.", self::buildMainMenuKeyboard($user));
            return;
        }

        // Global Commands
        if ($text === '/start' || $text === '/menu' || $text === '🏠 Главное меню') {
            telegramClearState($pdo, (string)$fromId);
            self::sendMainMenu($chatId, $user);
            return;
        }

        if ($text === '/help') {
            self::sendHelpMessage($chatId, $user);
            return;
        }

        if ($text === '/my' || $text === '📂 Мои заявки') {
            telegramClearState($pdo, (string)$fromId);
            self::sendOrdersList($pdo, $chatId, $user, 'all', 1, false);
            return;
        }

        if ($text === '/report' || $text === '📊 Мой отчет') {
            telegramClearState($pdo, (string)$fromId);
            self::sendReportMenu($chatId, $user, false);
            return;
        }

        if (preg_match('/^\/view\s+(\d+)$/i', $text, $matches)) {
            telegramClearState($pdo, (string)$fromId);
            self::sendOrderDetail($pdo, $chatId, $user, (int)$matches[1], false);
            return;
        }

        // Process based on FSM State if any
        if ($userState && !empty($userState['state'])) {
            self::processStateInput($pdo, $chatId, $user, $userState, $message);
            return;
        }

        // If no active state and sent media
        if (!empty($message['photo']) || !empty($message['video']) || !empty($message['document'])) {
            telegramSend(
                $chatId,
                "ℹ️ Чтобы прикрепить фото/видео к заявке, сначала откройте нужную заявку и нажмите кнопку <b>[📷 Добавить фото/видео]</b>.",
                self::buildMainMenuKeyboard($user)
            );
            return;
        }

        // Default: If bare number sent, try looking up order #
        if (preg_match('/^#?(\d+)$/', $text, $matches)) {
            $orderId = (int)$matches[1];
            self::sendOrderDetail($pdo, $chatId, $user, $orderId, false);
            return;
        }

        // Otherwise show main menu
        self::sendMainMenu($chatId, $user);
    }

    /**
     * Handle incoming inline button clicks (Callback Queries).
     */
    private static function handleCallbackQuery(PDO $pdo, array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'] ?? '';
        $fromId = $callbackQuery['from']['id'] ?? null;
        $username = $callbackQuery['from']['username'] ?? null;
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;
        $messageId = $callbackQuery['message']['message_id'] ?? null;
        $data = (string)($callbackQuery['data'] ?? '');

        if (!$chatId || !$fromId) {
            return;
        }

        $user = telegramResolveUser($pdo, $fromId, $username);
        if (!$user) {
            telegramAnswerCallbackQuery($callbackId, "Вы не зарегистрированы в CRM.", true);
            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';

        switch ($action) {
            case 'main_menu':
                telegramAnswerCallbackQuery($callbackId);
                telegramClearState($pdo, (string)$fromId);
                self::editOrSendMainMenu($chatId, $messageId, $user);
                break;

            case 'orders_my':
                telegramAnswerCallbackQuery($callbackId);
                $filter = $parts[1] ?? 'all';
                $page = (int)($parts[2] ?? 1);
                self::sendOrdersList($pdo, $chatId, $user, $filter, $page, true, $messageId, false);
                break;

            case 'orders_all':
                telegramAnswerCallbackQuery($callbackId);
                if (empty($user['is_admin'])) {
                    telegramAnswerCallbackQuery($callbackId, "Доступ разрешен только администраторам.", true);
                    return;
                }
                $filter = $parts[1] ?? 'all';
                $page = (int)($parts[2] ?? 1);
                self::sendOrdersList($pdo, $chatId, $user, $filter, $page, true, $messageId, true);
                break;

            case 'view_order':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::sendOrderDetail($pdo, $chatId, $user, $orderId, true, $messageId);
                break;

            case 'add_media':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::startMediaUpload($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'order_notes':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::sendOrderNotesMenu($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'note_append_prompt':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::promptNoteInput($pdo, $chatId, $user, $orderId, 'append', $messageId);
                break;

            case 'note_replace_prompt':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::promptNoteInput($pdo, $chatId, $user, $orderId, 'replace', $messageId);
                break;

            case 'order_parts':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::sendOrderPartsMenu($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'part_stock_prompt':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::promptPartSearch($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'part_pick':
                $orderId = (int)($parts[1] ?? 0);
                $inventoryId = (int)($parts[2] ?? 0);
                self::addPartFromInventory($pdo, $chatId, $user, $orderId, $inventoryId, $callbackId, $messageId);
                break;

            case 'part_manual_prompt':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::promptManualPart($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'part_delete_list':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::sendPartsDeleteList($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'part_delete_exec':
                $orderId = (int)($parts[1] ?? 0);
                $itemId = (int)($parts[2] ?? 0);
                self::deleteOrderPartExec($pdo, $chatId, $user, $orderId, $itemId, $callbackId, $messageId);
                break;

            case 'change_status_menu':
                telegramAnswerCallbackQuery($callbackId);
                $orderId = (int)($parts[1] ?? 0);
                self::sendStatusChangeMenu($pdo, $chatId, $user, $orderId, $messageId);
                break;

            case 'set_status_exec':
                $orderId = (int)($parts[1] ?? 0);
                $newStatus = $parts[2] ?? '';
                self::executeStatusChange($pdo, $chatId, $user, $orderId, $newStatus, $callbackId, $messageId);
                break;

            case 'report_menu':
                telegramAnswerCallbackQuery($callbackId);
                self::sendReportMenu($chatId, $user, true, $messageId);
                break;

            case 'report_period':
                telegramAnswerCallbackQuery($callbackId);
                $period = $parts[1] ?? 'week';
                self::sendCalculatedReport($pdo, $chatId, $user, $period, $messageId);
                break;

            case 'order_search_prompt':
                telegramAnswerCallbackQuery($callbackId);
                self::promptOrderSearch($pdo, $chatId, $user, $messageId);
                break;

            case 'profile':
                telegramAnswerCallbackQuery($callbackId);
                self::sendProfileInfo($chatId, $user, $messageId);
                break;

            default:
                telegramAnswerCallbackQuery($callbackId, "Неизвестное действие.");
                break;
        }
    }

    /**
     * Process multi-step input according to active state.
     */
    private static function processStateInput(PDO $pdo, $chatId, array $user, array $userState, array $message): void
    {
        $state = $userState['state'];
        $orderId = $userState['order_id'];
        $fromId = $message['from']['id'];
        $text = trim((string)($message['text'] ?? ''));

        switch ($state) {
            case 'WAITING_MEDIA':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                // Check for photo
                if (!empty($message['photo'])) {
                    $photos = $message['photo'];
                    $largestPhoto = end($photos);
                    $fileId = $largestPhoto['file_id'];
                    $res = telegramSaveMediaAttachment($pdo, $orderId, $fileId, 'photo_' . date('Ymd_His') . '.jpg');
                    if ($res['success']) {
                        $replyMarkup = [
                            'inline_keyboard' => [
                                [['text' => '✅ Завершить загрузку', 'callback_data' => "view_order:{$orderId}"]],
                            ]
                        ];
                        telegramSend($chatId, "✅ <b>Фото прикреплено к заявке #{$orderId}!</b>\n\nВы можете отправить еще фото/видео или нажать «Завершить».", $replyMarkup);
                    } else {
                        telegramSend($chatId, "❌ " . telegramHtml($res['message']));
                    }
                    return;
                }

                // Check for video
                if (!empty($message['video'])) {
                    $fileId = $message['video']['file_id'];
                    $clientName = $message['video']['file_name'] ?? ('video_' . date('Ymd_His') . '.mp4');
                    $res = telegramSaveMediaAttachment($pdo, $orderId, $fileId, $clientName);
                    if ($res['success']) {
                        $replyMarkup = [
                            'inline_keyboard' => [
                                [['text' => '✅ Завершить загрузку', 'callback_data' => "view_order:{$orderId}"]],
                            ]
                        ];
                        telegramSend($chatId, "✅ <b>Видео прикреплено к заявке #{$orderId}!</b>\n\nВы можете отправить еще файлы или нажать «Завершить».", $replyMarkup);
                    } else {
                        telegramSend($chatId, "❌ " . telegramHtml($res['message']));
                    }
                    return;
                }

                // Check for document (e.g. uncompressed photo/video)
                if (!empty($message['document'])) {
                    $fileId = $message['document']['file_id'];
                    $clientName = $message['document']['file_name'] ?? ('file_' . date('Ymd_His'));
                    $res = telegramSaveMediaAttachment($pdo, $orderId, $fileId, $clientName);
                    if ($res['success']) {
                        $replyMarkup = [
                            'inline_keyboard' => [
                                [['text' => '✅ Завершить загрузку', 'callback_data' => "view_order:{$orderId}"]],
                            ]
                        ];
                        telegramSend($chatId, "✅ <b>Файл прикреплен к заявке #{$orderId}!</b>", $replyMarkup);
                    } else {
                        telegramSend($chatId, "❌ " . telegramHtml($res['message']));
                    }
                    return;
                }

                telegramSend($chatId, "📷 Отправьте фото или видео в чат, либо нажмите кнопку ниже для завершения:", [
                    'inline_keyboard' => [
                        [['text' => '✅ Завершить загрузку', 'callback_data' => "view_order:{$orderId}"]],
                    ]
                ]);
                break;

            case 'WAITING_NOTE_APPEND':
            case 'WAITING_NOTE_REPLACE':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                if ($text === '') {
                    telegramSend($chatId, "⚠️ Пожалуйста, введите текст комментария.");
                    return;
                }

                $stmt = $pdo->prepare('SELECT technician_notes FROM orders WHERE id = ?');
                $stmt->execute([$orderId]);
                $currentNotes = (string)$stmt->fetchColumn();

                if ($state === 'WAITING_NOTE_APPEND') {
                    $datePrefix = date('d.m H:i');
                    $newNotes = trim($currentNotes) !== ''
                        ? $currentNotes . "\n[" . $datePrefix . "] " . $text
                        : "[" . $datePrefix . "] " . $text;
                } else {
                    $newNotes = $text;
                }

                $upd = $pdo->prepare('UPDATE orders SET technician_notes = ? WHERE id = ?');
                $upd->execute([$newNotes, $orderId]);

                telegramClearState($pdo, (string)$fromId);
                telegramSend($chatId, "✅ <b>Комментарий к заявке #{$orderId} успешно сохранен!</b>");
                self::sendOrderDetail($pdo, $chatId, $user, $orderId, false);
                break;

            case 'WAITING_PART_SEARCH':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                if (mb_strlen($text) < 2) {
                    telegramSend($chatId, "⚠️ Введите минимум 2 символа для поиска запчасти на складе:");
                    return;
                }

                $stmt = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory WHERE part_name LIKE ? OR sku LIKE ? ORDER BY quantity DESC LIMIT 8");
                $param = "%{$text}%";
                $stmt->execute([$param, $param]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($items)) {
                    $replyMarkup = [
                        'inline_keyboard' => [
                            [['text' => '🔍 Искать снова', 'callback_data' => "part_stock_prompt:{$orderId}"]],
                            [['text' => '➕ Добавить вручную', 'callback_data' => "part_manual_prompt:{$orderId}"]],
                            [['text' => '⬅️ К заявке', 'callback_data' => "view_order:{$orderId}"]],
                        ]
                    ];
                    telegramSend($chatId, "🔍 По запросу «<b>" . telegramHtml($text) . "</b>» на складе ничего не найдено.", $replyMarkup);
                    return;
                }

                $keyboard = [];
                foreach ($items as $item) {
                    $label = "📦 " . $item['part_name'] . " (" . (int)$item['quantity'] . " шт.) — " . formatMoney($item['sale_price']);
                    $keyboard[] = [['text' => $label, 'callback_data' => "part_pick:{$orderId}:{$item['id']}"]];
                }
                $keyboard[] = [['text' => '❌ Отмена', 'callback_data' => "order_parts:{$orderId}"]];

                telegramSend($chatId, "📦 <b>Результаты поиска по складу:</b>\n\nНажмите на нужную деталь для добавления в заявку #{$orderId}:", ['inline_keyboard' => $keyboard]);
                break;

            case 'WAITING_PART_MANUAL':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                // Format: Name | Price | Source
                $parts = array_map('trim', explode('|', $text));
                $partName = $parts[0] ?? '';
                $priceRaw = isset($parts[1]) ? str_replace([' ', 'Kč', 'kc', 'czk'], '', $parts[1]) : '';
                $source = $parts[2] ?? 'Вручную';

                if ($partName === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
                    telegramSend(
                        $chatId,
                        "⚠️ <b>Неверный формат.</b>\n\nВведите в формате:\n<code>Название детали | Цена | Источник</code>\n\n<i>Пример: Дисплей OLED | 2400 | FixParts</i>\n\nИли отправьте /cancel для отмены."
                    );
                    return;
                }

                $price = (float)$priceRaw;
                $statusStmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
                $statusStmt->execute([$orderId]);
                try {
                    OrderStatusService::assertClosedOrderEditable(canonicalOrderStatus((string)$statusStmt->fetchColumn()), !empty($user['is_admin']));
                } catch (Exception $e) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ " . telegramHtml($e->getMessage()));
                    return;
                }
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, part_name, source, quantity, price) VALUES (?, NULL, ?, ?, 1, ?)");
                $stmt->execute([$orderId, $partName, $source, $price]);

                telegramClearState($pdo, (string)$fromId);
                telegramSend($chatId, "✅ <b>Деталь «" . telegramHtml($partName) . "» добавлена к заявке #{$orderId}!</b>");
                self::sendOrderDetail($pdo, $chatId, $user, $orderId, false);
                break;

            case 'WAITING_CANCEL_REASON':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                if ($text === '') {
                    telegramSend($chatId, "⚠️ Пожалуйста, укажите причину отмены/отказа от ремонта:");
                    return;
                }

                $targetStatus = $userState['temp_data']['target_status'] ?? 'Repair Cancelled';
                self::commitStatusChange($pdo, $chatId, $user, $orderId, $targetStatus, null, $text);
                break;

            case 'WAITING_FINAL_COST':
                if (!$orderId || !telegramCanAccessOrder($pdo, $user, $orderId)) {
                    telegramClearState($pdo, (string)$fromId);
                    telegramSend($chatId, "❌ Ошибка доступа к заявке #{$orderId}.");
                    return;
                }

                $cleaned = str_replace([' ', 'Kč', 'kc', 'czk'], '', $text);
                if (!is_numeric($cleaned) || (float)$cleaned <= 0) {
                    telegramSend($chatId, "⚠️ Для статуса «Выдан» укажите положительную итоговую стоимость (например: <code>1500</code>):");
                    return;
                }

                $finalCost = (float)$cleaned;
                self::commitStatusChange($pdo, $chatId, $user, $orderId, 'Issued', $finalCost, null);
                break;

            case 'WAITING_ORDER_SEARCH':
                telegramClearState($pdo, (string)$fromId);
                self::executeOrderSearch($pdo, $chatId, $user, $text);
                break;

            default:
                telegramClearState($pdo, (string)$fromId);
                self::sendMainMenu($chatId, $user);
                break;
        }
    }

    /**
     * Send Main Menu.
     */
    public static function sendMainMenu($chatId, array $user): void
    {
        $name = telegramHtml($user['name']);
        $roleLabel = !empty($user['is_admin']) ? '⭐️ Администратор' : '🛠 Техник/Мастер';

        $text = "👋 Здравствуйте, <b>{$name}</b>!\n";
        $text .= "Ваша роль: <b>{$roleLabel}</b>\n\n";
        $text .= "Выберите необходимое действие в меню ниже:";

        telegramSend($chatId, $text, self::buildMainMenuInlineKeyboard($user));
    }

    public static function editOrSendMainMenu($chatId, ?int $messageId, array $user): void
    {
        $name = telegramHtml($user['name']);
        $roleLabel = !empty($user['is_admin']) ? '⭐️ Администратор' : '🛠 Техник/Мастер';

        $text = "👋 Здравствуйте, <b>{$name}</b>!\n";
        $text .= "Ваша роль: <b>{$roleLabel}</b>\n\n";
        $text .= "Выберите необходимое действие в меню ниже:";

        if ($messageId) {
            $res = telegramEditMessageText($chatId, $messageId, $text, self::buildMainMenuInlineKeyboard($user));
            if ($res) return;
        }

        telegramSend($chatId, $text, self::buildMainMenuInlineKeyboard($user));
    }

    private static function buildMainMenuInlineKeyboard(array $user): array
    {
        $keyboard = [];

        if (!empty($user['is_admin'])) {
            $keyboard[] = [
                ['text' => '🏢 Все заявки СЦ', 'callback_data' => 'orders_all:all:1'],
                ['text' => '📂 Мои заявки', 'callback_data' => 'orders_my:all:1'],
            ];
            $keyboard[] = [
                ['text' => '🔍 Поиск заявки', 'callback_data' => 'order_search_prompt'],
                ['text' => '📈 Отчет СЦ', 'callback_data' => 'report_period:month'],
            ];
        } else {
            $keyboard[] = [
                ['text' => '📂 Мои заявки', 'callback_data' => 'orders_my:all:1'],
                ['text' => '🔍 Поиск заявки', 'callback_data' => 'order_search_prompt'],
            ];
            $keyboard[] = [
                ['text' => '📊 Мой отчет', 'callback_data' => 'report_menu'],
                ['text' => '👤 Мой профиль', 'callback_data' => 'profile'],
            ];
        }

        return ['inline_keyboard' => $keyboard];
    }

    private static function buildMainMenuKeyboard(array $user): array
    {
        return [
            'keyboard' => [
                [['text' => '📂 Мои заявки'], ['text' => '📊 Мой отчет']],
                [['text' => '🏠 Главное меню'], ['text' => '❌ Отмена']],
            ],
            'resize_keyboard' => true,
        ];
    }

    /**
     * Send Paginated Orders List with Status Filters.
     */
    public static function sendOrdersList(
        PDO $pdo,
        $chatId,
        array $user,
        string $filter = 'all',
        int $page = 1,
        bool $isEdit = false,
        ?int $messageId = null,
        bool $isGlobalAll = false
    ): void {
        $limit = 7;
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if ($isGlobalAll && !empty($user['is_admin'])) {
            // Admin viewing all orders
        } else {
            // Technician viewing only own orders
            $where[] = 'o.technician_id = ?';
            $params[] = $user['technician_id'];
        }

        switch ($filter) {
            case 'active':
                $where[] = "o.status NOT IN ('Issued', 'Issued Without Repair', 'Repair Cancelled', 'Collected', 'Cancelled')";
                break;
            case 'diagnostics':
                $where[] = "o.status IN ('Accepted', 'Diagnostics')";
                break;
            case 'repair':
                $where[] = "o.status IN ('In Repair', 'Approval')";
                break;
            case 'ready':
                $where[] = "o.status IN ('Ready')";
                break;
            case 'issued':
                $where[] = "o.status IN ('Issued', 'Collected')";
                break;
            case 'all':
            default:
                $where[] = "o.status NOT IN ('Issued', 'Issued Without Repair', 'Repair Cancelled', 'Collected', 'Cancelled')";
                break;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o {$whereSql}");
        $countStmt->execute($params);
        $totalOrders = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($totalOrders / $limit));
        $page = min($page, $totalPages);

        // Fetch page orders
        $stmt = $pdo->prepare(
            "SELECT o.id, o.device_brand, o.device_model, o.status, o.created_at, c.first_name, c.last_name
             FROM orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             {$whereSql}
             ORDER BY o.created_at DESC
             LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $listTypePrefix = $isGlobalAll ? 'orders_all' : 'orders_my';
        $titlePrefix = $isGlobalAll ? '🏢 <b>Все активные заявки СЦ</b>' : '📂 <b>Ваши заявки</b>';

        $text = "{$titlePrefix} (всего: {$totalOrders})\n";
        $text .= "Страница <b>{$page}</b> из <b>{$totalPages}</b>\n\n";

        $keyboard = [];

        // Filter buttons row
        $keyboard[] = [
            ['text' => ($filter === 'all' ? '🔘 Все' : 'Все'), 'callback_data' => "{$listTypePrefix}:all:1"],
            ['text' => ($filter === 'repair' ? '🔘 В работе' : 'В работе'), 'callback_data' => "{$listTypePrefix}:repair:1"],
            ['text' => ($filter === 'ready' ? '🔘 Готовы' : 'Готовы'), 'callback_data' => "{$listTypePrefix}:ready:1"],
        ];

        if (empty($orders)) {
            $text .= "<i>Заявок в этой категории не найдено.</i>\n";
        } else {
            foreach ($orders as $o) {
                $statusIcon = self::getStatusEmoji($o['status']);
                $label = "{$statusIcon} #{$o['id']} " . $o['device_brand'] . " " . $o['device_model'] . " [" . getStatusLabel($o['status']) . "]";
                $keyboard[] = [['text' => $label, 'callback_data' => "view_order:{$o['id']}"]];
            }
        }

        // Pagination row
        $navRow = [];
        if ($page > 1) {
            $prevPage = $page - 1;
            $navRow[] = ['text' => '⬅️ Назад', 'callback_data' => "{$listTypePrefix}:{$filter}:{$prevPage}"];
        }
        if ($page < $totalPages) {
            $nextPage = $page + 1;
            $navRow[] = ['text' => 'Вперед ➡️', 'callback_data' => "{$listTypePrefix}:{$filter}:{$nextPage}"];
        }
        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }

        $keyboard[] = [['text' => '🏠 Главное меню', 'callback_data' => 'main_menu']];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($isEdit && $messageId) {
            $res = telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
            if ($res) return;
        }

        telegramSend($chatId, $text, $replyMarkup);
    }

    /**
     * Send Single Order Detail Card.
     */
    public static function sendOrderDetail(
        PDO $pdo,
        $chatId,
        array $user,
        int $orderId,
        bool $isEdit = false,
        ?int $messageId = null
    ): void {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            $errorMsg = "⛔ <b>Доступ запрещен.</b>\nЗаявка #{$orderId} не найдена или назначена другому специалисту.";
            if ($isEdit && $messageId) {
                telegramEditMessageText($chatId, $messageId, $errorMsg, ['inline_keyboard' => [[['text' => '🏠 Главное меню', 'callback_data' => 'main_menu']]]]);
            } else {
                telegramSend($chatId, $errorMsg);
            }
            return;
        }

        $stmt = $pdo->prepare(
            "SELECT o.*, c.first_name, c.last_name, c.phone, c.email, t.name as tech_name
             FROM orders o
             LEFT JOIN customers c ON o.customer_id = c.id
             LEFT JOIN technicians t ON o.technician_id = t.id
             WHERE o.id = ?"
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            telegramSend($chatId, "❌ Заявка #{$orderId} не найдена.");
            return;
        }

        // Count attachments
        $attStmt = $pdo->prepare("SELECT COUNT(*) FROM order_attachments WHERE order_id = ?");
        $attStmt->execute([$orderId]);
        $attachmentsCount = (int)$attStmt->fetchColumn();

        // Fetch parts
        $partsStmt = $pdo->prepare("SELECT part_name, quantity, price FROM order_items WHERE order_id = ?");
        $partsStmt->execute([$orderId]);
        $orderItems = $partsStmt->fetchAll(PDO::FETCH_ASSOC);

        $statusLabel = getStatusLabel($order['status']);
        $statusIcon = self::getStatusEmoji($order['status']);
        $cost = $order['final_cost'] !== null ? $order['final_cost'] : ($order['estimated_cost'] ?? 0);

        $card = "📑 <b>Заявка #{$order['id']}</b> — {$statusIcon} <b>{$statusLabel}</b>\n";
        $card .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $card .= "📱 <b>Устройство:</b> " . telegramHtml($order['device_brand'] . ' ' . $order['device_model']) . "\n";
        if (!empty($order['serial_number'])) {
            $card .= "🔢 <b>S/N:</b> <code>" . telegramHtml($order['serial_number']) . "</code>\n";
        }
        $card .= "👤 <b>Клиент:</b> " . telegramHtml($order['first_name'] . ' ' . $order['last_name']) . "\n";
        $card .= "📞 <b>Телефон:</b> <code>" . telegramHtml($order['phone']) . "</code>\n";
        if (!empty($order['tech_name'])) {
            $card .= "👨‍🔧 <b>Мастер:</b> " . telegramHtml($order['tech_name']) . "\n";
        }
        $card .= "📝 <b>Неисправность:</b> <i>" . telegramHtml($order['problem_description'] ?: 'Не указана') . "</i>\n";

        if (!empty($order['technician_notes'])) {
            $card .= "\n💬 <b>Заметка к ремонту:</b>\n<i>" . telegramHtml($order['technician_notes']) . "</i>\n";
        } else {
            $card .= "\n💬 <b>Заметка к ремонту:</b> <i>(нет заметок)</i>\n";
        }

        $card .= "\n🔧 <b>Запчасти:</b> ";
        if (empty($orderItems)) {
            $card .= "<i>(не добавлены)</i>\n";
        } else {
            $card .= "\n";
            foreach ($orderItems as $item) {
                $card .= "  • " . telegramHtml($item['part_name']) . " (" . (int)$item['quantity'] . " шт. × " . formatMoney($item['price']) . ")\n";
            }
        }

        $card .= "💰 <b>Итоговая стоимость:</b> " . formatMoney($cost) . "\n";
        $card .= "📎 <b>Медиа-файлов:</b> {$attachmentsCount} шт.\n";

        // Build Inline Action Keyboard
        $keyboard = [
            [
                ['text' => '📷 Фото/Видео (' . $attachmentsCount . ')', 'callback_data' => "add_media:{$orderId}"],
                ['text' => '📝 Заметка', 'callback_data' => "order_notes:{$orderId}"],
            ],
            [
                ['text' => '🔧 Запчасти (' . count($orderItems) . ')', 'callback_data' => "order_parts:{$orderId}"],
                ['text' => '🔄 Сменить статус', 'callback_data' => "change_status_menu:{$orderId}"],
            ],
            [
                ['text' => '⬅️ К списку заявок', 'callback_data' => 'orders_my:all:1'],
                ['text' => '🏠 Меню', 'callback_data' => 'main_menu'],
            ]
        ];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($isEdit && $messageId) {
            $res = telegramEditMessageText($chatId, $messageId, $card, $replyMarkup);
            if ($res) return;
        }

        telegramSend($chatId, $card, $replyMarkup);
    }

    /**
     * Start Media Upload Flow.
     */
    public static function startMediaUpload(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramSend($chatId, "⛔ Доступ к заявке #{$orderId} запрещен.");
            return;
        }

        telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_MEDIA', $orderId);

        $text = "📷 <b>Загрузка фото/видео для заявки #{$orderId}</b>\n\n";
        $text .= "Отправьте фото (как фото или файл) или видео в этот чат.\n";
        $text .= "Можно отправить сразу несколько файлов подряд со смартфона.\n\n";
        $text .= "По окончании нажмите «Завершить загрузку» ниже.";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '✅ Завершить загрузку', 'callback_data' => "view_order:{$orderId}"]],
                [['text' => '❌ Отмена', 'callback_data' => "view_order:{$orderId}"]],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    /**
     * Order Notes Submenu.
     */
    public static function sendOrderNotesMenu(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramSend($chatId, "⛔ Доступ к заявке #{$orderId} запрещен.");
            return;
        }

        $stmt = $pdo->prepare('SELECT technician_notes FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $notes = (string)$stmt->fetchColumn();

        $text = "📝 <b>Комментарии к ремонту (Заявка #{$orderId})</b>\n\n";
        if (trim($notes) !== '') {
            $text .= "Текущая заметка:\n<i>" . telegramHtml($notes) . "</i>\n\n";
        } else {
            $text .= "<i>Заметок пока нет.</i>\n\n";
        }
        $text .= "Выберите действие:";

        $keyboard = [
            [
                ['text' => '➕ Дописать в конец', 'callback_data' => "note_append_prompt:{$orderId}"],
                ['text' => '✏️ Переписать заново', 'callback_data' => "note_replace_prompt:{$orderId}"],
            ],
            [
                ['text' => '⬅️ Назад к заявке', 'callback_data' => "view_order:{$orderId}"],
            ]
        ];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function promptNoteInput(PDO $pdo, $chatId, array $user, int $orderId, string $mode, ?int $messageId = null): void
    {
        $state = ($mode === 'append') ? 'WAITING_NOTE_APPEND' : 'WAITING_NOTE_REPLACE';
        telegramSetState($pdo, (string)$user['telegram_id'], $state, $orderId);

        $actionWord = ($mode === 'append') ? 'дописать' : 'записать';
        $text = "📝 <b>Заявка #{$orderId}</b>\n\nВведите текст, который хотите {$actionWord} в комментарий к ремонту:\n\n<i>(Или отправьте /cancel для отмены)</i>";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '❌ Отмена', 'callback_data' => "view_order:{$orderId}"]],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    /**
     * Order Parts Submenu.
     */
    public static function sendOrderPartsMenu(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramSend($chatId, "⛔ Доступ к заявке #{$orderId} запрещен.");
            return;
        }

        $stmt = $pdo->prepare("SELECT id, part_name, source, quantity, price FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $text = "🔧 <b>Запчасти для заявки #{$orderId}</b>\n\n";
        if (empty($items)) {
            $text .= "<i>К этой заявке еще не привязаны детали.</i>\n\n";
        } else {
            $text .= "Установленные запчасти:\n";
            $totalParts = 0.0;
            foreach ($items as $idx => $item) {
                $num = $idx + 1;
                $sum = (float)$item['price'] * (int)$item['quantity'];
                $totalParts += $sum;
                $text .= "<b>{$num}.</b> " . telegramHtml($item['part_name']) . " — " . (int)$item['quantity'] . " шт. × " . formatMoney($item['price']) . " = " . formatMoney($sum) . "\n";
            }
            $text .= "\nИтого запчасти: <b>" . formatMoney($totalParts) . "</b>\n\n";
        }

        $keyboard = [
            [
                ['text' => '➕ Со склада', 'callback_data' => "part_stock_prompt:{$orderId}"],
                ['text' => '➕ Вручную', 'callback_data' => "part_manual_prompt:{$orderId}"],
            ],
        ];

        if (!empty($items)) {
            $keyboard[] = [['text' => '❌ Удалить деталь', 'callback_data' => "part_delete_list:{$orderId}"]];
        }

        $keyboard[] = [['text' => '⬅️ Назад к заявке', 'callback_data' => "view_order:{$orderId}"]];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function promptPartSearch(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_PART_SEARCH', $orderId);

        $text = "📦 <b>Поиск детали на складе для заявки #{$orderId}</b>\n\nВведите название детали или артикул (например: <code>аккумулятор iphone 13</code> или <code>дисплей</code>):";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '❌ Отмена', 'callback_data' => "order_parts:{$orderId}"]],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function addPartFromInventory(PDO $pdo, $chatId, array $user, int $orderId, int $inventoryId, string $callbackId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramAnswerCallbackQuery($callbackId, "Доступ запрещен.", true);
            return;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT technician_id, status FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Заявка не найдена.");
            }

            OrderStatusService::assertClosedOrderEditable(canonicalOrderStatus((string)$order['status']), !empty($user['is_admin']));
            $orderIsConsuming = in_array(canonicalOrderStatus($order['status']), ['Ready', 'Issued'], true);

            $invStmt = $pdo->prepare("SELECT part_name, quantity, sale_price, cost_price FROM inventory WHERE id = ? FOR UPDATE");
            $invStmt->execute([$inventoryId]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);

            if (!$inv) {
                throw new Exception("Деталь не найдена на складе.");
            }

            if ($orderIsConsuming && $inv['quantity'] < 1) {
                throw new Exception("Недостаточно остатка на складе.");
            }

            $insertStmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, part_name, quantity, price, cost_price) VALUES (?, ?, ?, 1, ?, ?)");
            $insertStmt->execute([$orderId, $inventoryId, $inv['part_name'], $inv['sale_price'], $inv['cost_price']]);

            if ($orderIsConsuming) {
                changeInventoryQuantity($inventoryId, -1);
            }

            $pdo->commit();

            telegramAnswerCallbackQuery($callbackId, "Деталь добавлена!");
            self::sendOrderPartsMenu($pdo, $chatId, $user, $orderId, $messageId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            telegramAnswerCallbackQuery($callbackId, "Ошибка: " . publicExceptionMessage($e), true);
        }
    }

    public static function promptManualPart(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_PART_MANUAL', $orderId);

        $text = "➕ <b>Добавление детали вручную (Заявка #{$orderId})</b>\n\n";
        $text .= "Введите данные через вертикальную черту <b>|</b> в формате:\n";
        $text .= "<code>Название | Цена | Поставщик</code>\n\n";
        $text .= "<i>Пример: Дисплей OLED | 2400 | FixParts</i>";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '❌ Отмена', 'callback_data' => "order_parts:{$orderId}"]],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function sendPartsDeleteList(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramSend($chatId, "⛔ Доступ запрещен.");
            return;
        }

        $stmt = $pdo->prepare("SELECT id, part_name, quantity, price FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $text = "❌ <b>Удаление детали (Заявка #{$orderId})</b>\n\nНажмите на деталь, которую хотите удалить:";

        $keyboard = [];
        foreach ($items as $item) {
            $keyboard[] = [['text' => '🗑 ' . $item['part_name'] . ' (' . formatMoney($item['price']) . ')', 'callback_data' => "part_delete_exec:{$orderId}:{$item['id']}"]];
        }
        $keyboard[] = [['text' => '⬅️ Назад', 'callback_data' => "order_parts:{$orderId}"]];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function deleteOrderPartExec(PDO $pdo, $chatId, array $user, int $orderId, int $itemId, string $callbackId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramAnswerCallbackQuery($callbackId, "Доступ запрещен.", true);
            return;
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT oi.*, o.status FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id = ? AND oi.order_id = ? FOR UPDATE");
            $stmt->execute([$itemId, $orderId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Деталь не найдена.");
            }
            OrderStatusService::assertClosedOrderEditable(canonicalOrderStatus((string)$item['status']), !empty($user['is_admin']));

            if (in_array(canonicalOrderStatus($item['status']), ['Ready', 'Issued'], true) && !empty($item['inventory_id'])) {
                changeInventoryQuantity($item['inventory_id'], (int)$item['quantity']);
            }

            $delStmt = $pdo->prepare("DELETE FROM order_items WHERE id = ?");
            $delStmt->execute([$itemId]);

            $pdo->commit();

            telegramAnswerCallbackQuery($callbackId, "Деталь удалена!");
            self::sendOrderPartsMenu($pdo, $chatId, $user, $orderId, $messageId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            telegramAnswerCallbackQuery($callbackId, "Ошибка: " . publicExceptionMessage($e), true);
        }
    }

    /**
     * Status Change Menu.
     */
    public static function sendStatusChangeMenu(PDO $pdo, $chatId, array $user, int $orderId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramSend($chatId, "⛔ Доступ запрещен.");
            return;
        }

        $stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $currentStatus = (string)$stmt->fetchColumn();

        $text = "🔄 <b>Смена статуса заявки #{$orderId}</b>\n\n";
        $text .= "Текущий статус: <b>" . getStatusLabel($currentStatus) . "</b>\n\n";
        $text .= "Выберите новый статус:";

        $statuses = [
            'Diagnostics' => '🔍 Диагностика',
            'Approval' => '⏳ Согласование',
            'In Repair' => '🛠 В ремонте',
            'Ready' => '✅ Готов',
            'Issued' => '📦 Выдан',
            'Repair Cancelled' => '❌ Отказ / Без ремонта',
        ];

        $keyboard = [];
        $row = [];
        foreach ($statuses as $stKey => $stLabel) {
            $isCurrent = (canonicalOrderStatus($currentStatus) === canonicalOrderStatus($stKey));
            $btnText = $isCurrent ? "🔘 {$stLabel}" : $stLabel;
            $row[] = ['text' => $btnText, 'callback_data' => "set_status_exec:{$orderId}:{$stKey}"];
            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $keyboard[] = $row;
        }

        $keyboard[] = [['text' => '⬅️ Назад к заявке', 'callback_data' => "view_order:{$orderId}"]];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function executeStatusChange(PDO $pdo, $chatId, array $user, int $orderId, string $newStatus, string $callbackId, ?int $messageId = null): void
    {
        if (!telegramCanAccessOrder($pdo, $user, $orderId)) {
            telegramAnswerCallbackQuery($callbackId, "Доступ запрещен.", true);
            return;
        }

        $stmt = $pdo->prepare('SELECT status, final_cost, estimated_cost, order_type, shipping_method FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            telegramAnswerCallbackQuery($callbackId, "Заявка не найдена.", true);
            return;
        }

        $canonicalCurrent = canonicalOrderStatus($order['status']);
        $canonicalNew = canonicalOrderStatus($newStatus);

        // Terminal status guard
        if (!empty($user['is_admin']) === false && OrderStatusService::isTerminal($canonicalCurrent) && $canonicalNew !== $canonicalCurrent) {
            telegramAnswerCallbackQuery($callbackId, "Нельзя изменить статус уже закрытой заявки.", true);
            return;
        }

        // Issued needs a stored handover method; the bot cannot ask for one.
        if ($canonicalNew === 'Issued' && !OrderStatusService::issuedShippingSatisfied($order['shipping_method'] ?? null)) {
            telegramAnswerCallbackQuery($callbackId, "Сначала укажите способ выдачи в CRM.", true);
            return;
        }

        // Issued requires final cost unless Warranty («Рекламация») or Self Pickup (binding rule).
        if ($canonicalNew === 'Issued' && OrderStatusService::issuedRequiresFinalCost($order['order_type'] ?? null, $order['shipping_method'] ?? null)) {
            $cost = $order['final_cost'] !== null ? (float)$order['final_cost'] : ((float)($order['estimated_cost'] ?? 0));
            if ($cost <= 0) {
                telegramAnswerCallbackQuery($callbackId);
                telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_FINAL_COST', $orderId);
                telegramSend($chatId, "💰 <b>Заявка #{$orderId} переводится в «Выдан».</b>\n\nУкажите итоговую стоимость ремонта (например: <code>1500</code>):");
                return;
            }
        }

        // Cancel requires reason
        if (in_array($canonicalNew, ['Repair Cancelled', 'Issued Without Repair'], true)) {
            telegramAnswerCallbackQuery($callbackId);
            telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_CANCEL_REASON', $orderId, ['target_status' => $canonicalNew]);
            telegramSend($chatId, "📝 <b>Укажите причину отмены/отказа для заявки #{$orderId}:</b>");
            return;
        }

        self::commitStatusChange($pdo, $chatId, $user, $orderId, $canonicalNew, null, null, $messageId);
        telegramAnswerCallbackQuery($callbackId, "Статус обновлен!");
    }

    private static function commitStatusChange(
        PDO $pdo,
        $chatId,
        array $user,
        int $orderId,
        string $newStatus,
        ?float $finalCost = null,
        ?string $cancelReason = null,
        ?int $messageId = null
    ): void {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception("Заявка не найдена.");
            }

            $currentStatus = $order['status'];
            $costToUse = $finalCost !== null ? $finalCost : ($order['final_cost'] ?? $order['estimated_cost']);

            // Re-validate under the row lock: the order may have changed while the bot waited for input.
            $canonicalCurrent = canonicalOrderStatus($currentStatus);
            $canonicalNew = canonicalOrderStatus($newStatus);
            OrderStatusService::assertCanChangeFromTerminal($canonicalCurrent, $canonicalNew, !empty($user['is_admin']));
            OrderStatusService::assertIssuedRequirements(
                $canonicalNew,
                $costToUse,
                $order['shipping_method'] ?? null,
                true,
                $order['order_type'] ?? null
            );
            OrderStatusService::assertCancellationReason(
                $canonicalNew,
                $cancelReason,
                crmMigrationColumnExists($pdo, 'orders', 'cancellation_reason'),
                true,
                $order['cancellation_reason'] ?? null
            );
            $newStatus = getOrderStatusStorageValue($canonicalNew);

            if ($finalCost !== null) {
                $updCost = $pdo->prepare("UPDATE orders SET final_cost = ? WHERE id = ?");
                $updCost->execute([$finalCost, $orderId]);
            }

            if ($cancelReason !== null && crmMigrationColumnExists($pdo, 'orders', 'cancellation_reason')) {
                $updReason = $pdo->prepare("UPDATE orders SET cancellation_reason = ? WHERE id = ?");
                $updReason->execute([$cancelReason, $orderId]);
            }

            $updStatus = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $updStatus->execute([$newStatus, $orderId]);

            // Run central OrderStatusService transition rules inside transaction
            $transition = OrderStatusService::applyInTransaction(
                $pdo,
                $orderId,
                $currentStatus,
                $newStatus,
                $costToUse
            );

            $pdo->commit();
            telegramClearState($pdo, (string)$user['telegram_id']);

            // Post-commit side effects: MyInvoice sync & admin notification
            OrderStatusService::afterCommit(
                $pdo,
                $orderId,
                $currentStatus,
                $newStatus,
                $costToUse,
                $transition['invoice_to_sync'] ?? null,
                true
            );

            telegramSend($chatId, "✅ <b>Статус заявки #{$orderId} успешно изменен на «" . getStatusLabel($newStatus) . "»!</b>");
            self::sendOrderDetail($pdo, $chatId, $user, $orderId, false);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("commitStatusChange error: " . $e->getMessage());
            telegramSend($chatId, "❌ Ошибка при смене статуса: " . telegramHtml(publicExceptionMessage($e)));
        }
    }

    /**
     * Reports Submenu.
     */
    public static function sendReportMenu($chatId, array $user, bool $isEdit = false, ?int $messageId = null): void
    {
        $text = "📊 <b>Отчеты и выработка</b>\n\nВыберите период для формирования отчета:";

        $keyboard = [
            [
                ['text' => '📅 За сегодня', 'callback_data' => 'report_period:today'],
                ['text' => '🗓 За эту неделю', 'callback_data' => 'report_period:week'],
            ],
            [
                ['text' => '📆 За этот месяц', 'callback_data' => 'report_period:month'],
            ],
            [
                ['text' => '🏠 Главное меню', 'callback_data' => 'main_menu'],
            ]
        ];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($isEdit && $messageId) {
            $res = telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
            if ($res) return;
        }

        telegramSend($chatId, $text, $replyMarkup);
    }

    public static function sendCalculatedReport(PDO $pdo, $chatId, array $user, string $period, ?int $messageId = null): void
    {
        switch ($period) {
            case 'today':
                $startDate = date('Y-m-d');
                $endDate = date('Y-m-d');
                $periodTitle = "за сегодня (" . date('d.m.Y') . ")";
                break;
            case 'month':
                $startDate = date('Y-m-01');
                $endDate = date('Y-m-t');
                $periodTitle = "за этот месяц (" . date('m.Y') . ")";
                break;
            case 'week':
            default:
                $startDate = date('Y-m-d', strtotime('monday this week'));
                $endDate = date('Y-m-d', strtotime('sunday this week'));
                $periodTitle = "за эту неделю ({$startDate} — {$endDate})";
                break;
        }

        if (!empty($user['is_admin']) && empty($user['technician_id'])) {
            // Admin overall workshop report. Keys come from crmEmptyDetailedStats().
            $all = getDetailedStatsBatch($pdo, $startDate, $endDate)['all'];
            $text = "📈 <b>Финансовый отчет СЦ {$periodTitle}</b>\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "💰 <b>Выручка от клиентов:</b> " . formatMoney($all['revenue']) . "\n";
            $text .= "🔩 <b>Себестоимость запчастей:</b> " . formatMoney($all['parts_cost']) . "\n";
            $text .= "🧾 <b>Доп. расходы:</b> " . formatMoney($all['expenses']) . "\n";
            $text .= "👨‍🔧 <b>Выплаты мастерам:</b> " . formatMoney($all['earnings']) . "\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "⭐️ <b>Чистый доход СЦ:</b> <b>" . formatMoney($all['sc_income']) . "</b>\n";
        } else {
            // Technician personal report, scoped to this technician only.
            $techId = (int)$user['technician_id'];
            $batch = getDetailedStatsBatch($pdo, $startDate, $endDate, $techId);
            $stats = $batch['by_technician'][$techId] ?? crmEmptyDetailedStats((float)($user['rate'] ?? 50));

            $text = "📊 <b>Ваш отчет {$periodTitle}</b>\n";
            $text .= "━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "👨‍🔧 <b>Мастер:</b> " . telegramHtml($user['name']) . "\n";
            $text .= "🏷 <b>Ваша ставка:</b> " . (float)$stats['engineer_rate'] . "%\n";
            $text .= "📦 <b>Выдано заказов:</b> <b>" . (int)$stats['finance_orders'] . " шт.</b>\n";
            $text .= "💰 <b>Сумма выполненных работ:</b> " . formatMoney($stats['revenue']) . "\n";
            $text .= "🔩 <b>Запчасти (себестоимость):</b> " . formatMoney($stats['parts_cost']) . "\n";
            if ((float)$stats['expenses'] > 0) {
                $text .= "🧾 <b>Доп. расходы:</b> " . formatMoney($stats['expenses']) . "\n";
            }
            $text .= "━━━━━━━━━━━━━━━━━━━━━\n";
            $text .= "💵 <b>ВАШ ЗАРАБОТОК:</b> <b>" . formatMoney($stats['earnings']) . "</b>\n";
        }

        $keyboard = [
            [
                ['text' => '📅 Сегодня', 'callback_data' => 'report_period:today'],
                ['text' => '🗓 Неделя', 'callback_data' => 'report_period:week'],
                ['text' => '📆 Месяц', 'callback_data' => 'report_period:month'],
            ],
            [
                ['text' => '🏠 Главное меню', 'callback_data' => 'main_menu'],
            ]
        ];

        $replyMarkup = ['inline_keyboard' => $keyboard];

        if ($messageId) {
            $res = telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
            if ($res) return;
        }

        telegramSend($chatId, $text, $replyMarkup);
    }

    /**
     * Search orders prompt & execution.
     */
    public static function promptOrderSearch(PDO $pdo, $chatId, array $user, ?int $messageId = null): void
    {
        telegramSetState($pdo, (string)$user['telegram_id'], 'WAITING_ORDER_SEARCH');

        $text = "🔍 <b>Поиск заявки</b>\n\nВведите номер заявки (например: <code>#1042</code> или <code>1042</code>), телефон клиента, имя или модель устройства:";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '❌ Отмена', 'callback_data' => 'main_menu']],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function executeOrderSearch(PDO $pdo, $chatId, array $user, string $query): void
    {
        $techId = !empty($user['is_admin']) ? null : $user['technician_id'];
        $result = searchOrdersList($pdo, $query, $techId, null, 6, 0, false);
        $orders = $result['orders'] ?? [];

        if (empty($orders)) {
            $replyMarkup = [
                'inline_keyboard' => [
                    [['text' => '🔍 Искать снова', 'callback_data' => 'order_search_prompt']],
                    [['text' => '🏠 Главное меню', 'callback_data' => 'main_menu']],
                ]
            ];
            telegramSend($chatId, "🔍 По запросу «<b>" . telegramHtml($query) . "</b>» ничего не найдено.", $replyMarkup);
            return;
        }

        $text = "🔍 <b>Результаты поиска по «" . telegramHtml($query) . "»:</b>\n\n";

        $keyboard = [];
        foreach ($orders as $o) {
            $statusIcon = self::getStatusEmoji($o['status']);
            $btnLabel = "{$statusIcon} #{$o['id']} " . $o['device_brand'] . " " . $o['device_model'];
            $keyboard[] = [['text' => $btnLabel, 'callback_data' => "view_order:{$o['id']}"]];
        }

        $keyboard[] = [
            ['text' => '🔍 Новый поиск', 'callback_data' => 'order_search_prompt'],
            ['text' => '🏠 Меню', 'callback_data' => 'main_menu'],
        ];

        telegramSend($chatId, $text, ['inline_keyboard' => $keyboard]);
    }

    /**
     * Send Profile info.
     */
    public static function sendProfileInfo($chatId, array $user, ?int $messageId = null): void
    {
        $text = "👤 <b>Ваш профиль в CRM</b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "Имя: <b>" . telegramHtml($user['name']) . "</b>\n";
        $text .= "Роль: <b>" . (!empty($user['is_admin']) ? 'Администратор' : 'Техник') . "</b>\n";
        if (!empty($user['rate'])) {
            $text .= "Ставка инженера: <b>" . (float)$user['rate'] . "%</b>\n";
        }
        $text .= "Telegram ID: <code>{$chatId}</code>\n";

        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => '📂 Мои заявки', 'callback_data' => 'orders_my:all:1']],
                [['text' => '🏠 Главное меню', 'callback_data' => 'main_menu']],
            ]
        ];

        if ($messageId) {
            telegramEditMessageText($chatId, $messageId, $text, $replyMarkup);
        } else {
            telegramSend($chatId, $text, $replyMarkup);
        }
    }

    public static function sendHelpMessage($chatId, array $user): void
    {
        $text = "ℹ️ <b>Справка по боту CRM ServisExpert</b>\n\n";
        $text .= "Бот позволяет быстро работать с вашими заявками в CRM:\n";
        $text .= "• <b>Просмотр заявок</b> — список активных заказов, детали, контакты клиентов.\n";
        $text .= "• <b>Фото и видео</b> — прикрепление медиа-отчетов прямо со смартфона.\n";
        $text .= "• <b>Заметки</b> — добавление и изменение заметок к ремонту.\n";
        $text .= "• <b>Запчасти</b> — списание деталей со склада или добавление вручную.\n";
        $text .= "• <b>Смена статусов</b> — перевод в «В ремонте», «Готов», «Выдан» и др.\n";
        $text .= "• <b>Личный отчет</b> — выработка и заработок за день/неделю/месяц.\n\n";
        $text .= "Команды:\n";
        $text .= "📂 /my — Мои активные заявки\n";
        $text .= "📊 /report — Мой отчет\n";
        $text .= "🏠 /menu — Главное меню\n";
        $text .= "❌ /cancel — Отмена текущего действия\n";

        telegramSend($chatId, $text, self::buildMainMenuInlineKeyboard($user));
    }

    private static function sendUnregisteredUserMessage($chatId, $fromId, ?string $username): void
    {
        $usernameClean = $username ? "@" . $username : "без никнейма";
        $msg = "❌ <b>Вы не зарегистрированы в CRM.</b>\n\n";
        $msg .= "Ваш цифровой ID: <code>{$fromId}</code>\n";
        $msg .= "Ваш никнейм: <code>" . telegramHtml($usernameClean) . "</code>\n\n";
        $msg .= "Попросите администратора добавить ваш цифровой ID <code>{$fromId}</code> в настройках вашего профиля CRM.";

        telegramSend($chatId, $msg);
    }

    private static function getStatusEmoji(string $status): string
    {
        switch (canonicalOrderStatus($status)) {
            case 'Accepted': return '📥';
            case 'Diagnostics': return '🔍';
            case 'Approval': return '⏳';
            case 'In Repair': return '🛠';
            case 'Ready': return '✅';
            case 'Issued': return '📦';
            case 'Issued Without Repair': return '⚠️';
            case 'Repair Cancelled': return '❌';
            default: return '🔘';
        }
    }
}
