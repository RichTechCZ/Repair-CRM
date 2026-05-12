<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();

// add_order.php returns a redirect (not JSON), so handle errors differently
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// CSRF validation
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(__('csrf_token_invalid'));
}

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
    die(__('missing_fields'));
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "INSERT INTO orders (customer_id, technician_id, device_type, order_type, device_brand, device_model,
         problem_description, technician_notes, serial_number, serial_number_2, pin_code, appearance, priority, estimated_cost, shipping_method)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $customer_id, $technician_id, $device_type, $order_type, $device_brand, $device_model,
        $problem_description, $technician_notes, $serial_number, $serial_number_2,
        $pin_code, $appearance, $priority, $estimated_cost, $shipping_method
    ]);
    $order_id = (int)$pdo->lastInsertId();

    logOrderStatusChange($order_id, '', 'New');

    // ── Secure file upload ────────────────────────────────────────────────────
    if (!empty($_FILES['files']['name'][0])) {
        $upload_dir = __DIR__ . '/../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        // Protect uploads from PHP execution
        $htaccess = $upload_dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                "# Deny PHP execution in uploads\n" .
                "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
                "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
                "RemoveType .php .phtml .php3 .php4 .php5\n"
            );
        }

        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        foreach ($_FILES['files']['tmp_name'] as $key => $tmp) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;

            $real_type = finfo_file($finfo, $tmp);
            if (!in_array($real_type, $allowed_types)) continue;
            if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) continue;

            $ext = strtolower(pathinfo($_FILES['files']['name'][$key], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) $ext = 'bin';

            $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)")
                    ->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($_FILES['files']['name'][$key])]);
            }
        }
        finfo_close($finfo);
    }

    $pdo->commit();

    // ── Telegram notification ─────────────────────────────────────────────────
    if ($technician_id) {
        $tech = $pdo->prepare("SELECT telegram_id, name FROM technicians WHERE id = ?");
        $tech->execute([$technician_id]);
        $techData = $tech->fetch();
        if ($techData && $techData['telegram_id']) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $link = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/../view_order.php?id=" . $order_id;
            $msg  = sprintf(__('tg_new_order'), $order_id) . "\n";
            $msg .= sprintf(__('tg_device'), "$device_brand $device_model") . "\n";
            $msg .= sprintf(__('tg_problem'), mb_substr($problem_description, 0, 100)) . "\n";
            $msg .= sprintf(__('tg_open_link'), $link);
            sendTelegramNotification($techData['telegram_id'], $msg);
        }
    }

    header("Location: ../orders.php");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("add_order error: " . $e->getMessage());
    die('Order creation failed. Please try again.');
}
?>
