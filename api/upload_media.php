<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

// CSRF validation
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

if (empty($_FILES['files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit;
}

$upload_dir = __DIR__ . '/../uploads/';
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];
$success_count = 0;

// Ensure upload directory exists
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Create .htaccess to prevent PHP execution in uploads folder
$htaccess = $upload_dir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess,
        "# Deny PHP execution in uploads\n" .
        "<FilesMatch \"\\.php$\">\n    Require all denied\n</FilesMatch>\n" .
        "RemoveHandler .php .phtml .php3 .php4 .php5\n" .
        "RemoveType .php .phtml .php3 .php4 .php5\n"
    );
}

// Use finfo for real MIME type detection (not $_FILES['type'] which can be spoofed)
$finfo = finfo_open(FILEINFO_MIME_TYPE);

foreach ($_FILES['files']['name'] as $key => $name) {
    if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) continue;

    $tmp = $_FILES['files']['tmp_name'][$key];

    // Detect real MIME type from file content
    $real_type = finfo_file($finfo, $tmp);

    if (!in_array($real_type, $allowed_types)) {
        error_log("Blocked upload attempt: type=$real_type, name=$name");
        continue;
    }

    // Additional validation: ensure images are real images
    if (strpos($real_type, 'image/') === 0 && getimagesize($tmp) === false) {
        error_log("Blocked fake image upload: name=$name");
        continue;
    }

    // Generate a cryptographically random filename (not guessable)
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'avi'];
    if (!in_array($ext, $allowed_exts)) $ext = 'bin';

    $new_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $path = $upload_dir . $new_name;

    if (move_uploaded_file($tmp, $path)) {
        $stmt = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$order_id, 'uploads/' . $new_name, $real_type, basename($name)]);
        $success_count++;
    }
}

finfo_close($finfo);

echo json_encode(['success' => true, 'count' => $success_count]);
?>
