<?php
// Form POST → redirect (not JSON).
// Must begin before shared includes: the failure handler redirects instead of
// returning JSON, so no include output may commit response headers first.
ob_start();
$orderCreateStage = 'config';

register_shutdown_function(static function () use (&$orderCreateStage): void {
    $fatal = error_get_last();
    $fatalTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if ($fatal === null || !in_array($fatal['type'], $fatalTypes, true)) {
        return;
    }

    // The diagnostics directory is denied by its .htaccess. Keep the record
    // compact and never include submitted customer or device data.
    $diagnostic = sprintf(
        "%s stage=%s type=%d message=%s%s",
        date('c'),
        $orderCreateStage,
        $fatal['type'],
        str_replace(["\r", "\n"], ' ', (string)$fatal['message']),
        PHP_EOL
    );
    @file_put_contents(__DIR__ . '/../temp/add_order_runtime.log', $diagnostic, FILE_APPEND | LOCK_EX);

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        $_SESSION['order_form_error'] = function_exists('__')
            ? __('order_create_failed')
            : 'Unable to create the order. Please try again.';
        header('Location: ../orders.php', true, 302);
    }
});

require_once __DIR__ . '/../includes/config.php';

// Handle the public entry point before API rate limiting. On this host the
// shared bootstrap cannot safely produce its form redirect for an anonymous
// request and Apache returns an empty 500 instead.
if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php', true, 302);
    exit;
}

require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/upload_security.php';
// Defensive: older production config.php may not load sensitive_data.php.
if (!function_exists('crmEncryptSensitiveValue')) {
    require_once __DIR__ . '/../includes/sensitive_data.php';
}
$orderCreateStage = 'api_bootstrap';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'json' => false,
    'rate' => 'add_order',
    'fail' => static function (string $message, int $status): void {
        if ($status === 401) {
            header('Location: ../login.php');
            exit;
        }
        $_SESSION['order_form_error'] = $message;
        header('Location: ../orders.php');
        exit;
    },
]);

// ── Input validation ──────────────────────────────────────────────────────────
$customer_id      = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
$technician_id    = filter_input(INPUT_POST, 'technician_id', FILTER_VALIDATE_INT) ?: null;
$device_type      = trim($_POST['device_type'] ?? 'Other');
$order_type       = trim($_POST['order_type'] ?? 'Non-Warranty');
$device_brand     = trim($_POST['device_brand'] ?? '');
$device_model     = trim($_POST['device_model'] ?? '');
$problem_description = trim($_POST['problem_description'] ?? '');
$technician_notes = trim($_POST['technician_notes'] ?? '');
$serial_number    = trim($_POST['serial_number'] ?? '');
$serial_number_2  = trim($_POST['serial_number_2'] ?? '');
$pin_code         = trim($_POST['pin_code'] ?? '');
$appearance       = trim($_POST['appearance'] ?? '');
$priority         = in_array($_POST['priority'] ?? '', ['High', 'Normal']) ? $_POST['priority'] : 'Normal';
$estimated_cost   = max(0, filter_input(INPUT_POST, 'estimated_cost', FILTER_VALIDATE_FLOAT) ?: 0);
$shipping_method  = trim($_POST['shipping_method'] ?? '') ?: null;

if (!$customer_id || !$device_model) {
    $_SESSION['order_form_error'] = __('missing_fields');
    header('Location: ../orders.php');
    exit;
}

if (($_SESSION['role'] ?? '') === 'technician') {
    $technician_id = (int)($_SESSION['tech_id'] ?? 0);
}

if (!currentUserCanCreateOrderForCustomer((int)$customer_id)) {
    $_SESSION['order_form_error'] = __('access_denied_msg');
    header('Location: ../orders.php');
    exit;
}

$storedUploadPaths = [];
try {
    $orderCreateStage = 'database_transaction';
    $pdo->beginTransaction();
    $initial_status = getDefaultOrderStatus();
    // Never accept ciphertext from the browser: crmEncryptSensitiveValue() passes "enc:v1:" values
    // through, so a copied ciphertext of another order would be decrypted for this one.
    $storedPinCode = crmSensitiveDataIsEncrypted($pin_code) ? null : crmEncryptSensitiveValue($pin_code);

    $customerLock = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
    $customerLock->execute([(int)$customer_id]);
    if (!$customerLock->fetchColumn()) {
        throw new RuntimeException('Customer not found.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO orders (customer_id, technician_id, device_type, order_type, device_brand, device_model,
         problem_description, technician_notes, serial_number, serial_number_2, pin_code, appearance, priority, estimated_cost, shipping_method, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $customer_id, $technician_id, $device_type, $order_type, $device_brand, $device_model,
        $problem_description, $technician_notes, $serial_number, $serial_number_2,
        $storedPinCode, $appearance, $priority, $estimated_cost, $shipping_method, $initial_status
    ]);
    $order_id = (int)$pdo->lastInsertId();
    crmEnsureOrderPublicStatusToken($pdo, $order_id);

    saveDeviceModelUsage($device_brand, $device_model);
    logOrderStatusChange($order_id, '', $initial_status);

    // ── Secure file upload ────────────────────────────────────────────────────
    if (!empty($_FILES['files']['name'][0])) {
        $orderCreateStage = 'attachment_processing';
        $uploadResult = crmStoreOrderUploads($pdo, $order_id, $_FILES['files']);
        $storedUploadPaths = $uploadResult['paths'];
        if ($uploadResult['rejected'] > 0) {
            $_SESSION['order_form_warning'] = sprintf(
                '%d attachment(s) were rejected because of type, size, or upload errors.',
                $uploadResult['rejected']
            );
        }
    }

    $orderCreateStage = 'database_commit';
    $pdo->commit();
    consumeCustomerOrderCreationGrant((int)$customer_id);

    // ── Telegram notification ─────────────────────────────────────────────────
    // The order is already committed. A delivery problem must never turn a
    // successful creation into an HTTP 500 or a misleading failure message.
    try {
        $orderCreateStage = 'telegram_notification';
        if ($technician_id) {
            $tech = $pdo->prepare("SELECT telegram_id, name FROM technicians WHERE id = ?");
            $tech->execute([$technician_id]);
            $techData = $tech->fetch();
            if ($techData && $techData['telegram_id']) {
                $link = crmPublicBaseUrl() . "/view_order.php?id=" . $order_id;
                $msg  = sprintf(__('tg_new_order'), $order_id) . "\n";
                $msg .= sprintf(__('tg_device'), telegramHtml("$device_brand $device_model")) . "\n";
                $msg .= sprintf(__('tg_problem'), telegramHtml(mb_substr($problem_description, 0, 100))) . "\n";
                $msg .= sprintf(__('tg_open_link'), telegramHtml($link));
                sendTelegramNotification($techData['telegram_id'], $msg);
            }
        }
    } catch (Throwable $e) {
        error_log('add_order Telegram notification error: ' . $e->getMessage());
    }

    $orderCreateStage = 'success_redirect';
    header('Location: ../orders.php?created_order_id=' . (int)$order_id);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    crmRemoveStoredUploadPaths($storedUploadPaths);
    error_log("add_order error: " . $e->getMessage());
    $_SESSION['order_form_error'] = __('order_create_failed');
    header('Location: ../orders.php');
    exit;
}
?>
