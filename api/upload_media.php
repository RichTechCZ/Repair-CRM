<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => __('unauthorized')]);
        exit;
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
        exit;
    }

    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }

    if (empty($_FILES['files']['name'][0])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No files uploaded']);
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/';
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
    $success_count = 0;

    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        throw new RuntimeException('Failed to create uploads directory');
    }

    $htaccess = $upload_dir . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Deny PHP execution in uploads\n" .
            "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
            "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
            "RemoveType .php .phtml .php3 .php4 .php5\n"
        );
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        throw new RuntimeException('Failed to initialize MIME detector');
    }

    foreach ($_FILES['files']['name'] as $key => $name) {
        if (($_FILES['files']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmp = $_FILES['files']['tmp_name'][$key] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }

        $real_type = finfo_file($finfo, $tmp);
        if (!in_array($real_type, $allowed_types, true)) {
            error_log("Blocked upload attempt: type=$real_type, name=$name");
            continue;
        }

        if (strpos($real_type, 'image/') === 0 && @getimagesize($tmp) === false) {
            error_log("Blocked fake image upload: name=$name");
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_exts, true)) {
            $ext = 'bin';
        }

        $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = $upload_dir . $new_name;

        if (move_uploaded_file($tmp, $path)) {
            $stmt = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($name)]);
            $success_count++;
        }
    }

    finfo_close($finfo);

    if (ob_get_length()) {
        ob_clean();
    }

    echo json_encode(['success' => true, 'count' => $success_count]);
} catch (Throwable $e) {
    if (isset($finfo) && $finfo) {
        finfo_close($finfo);
    }
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
