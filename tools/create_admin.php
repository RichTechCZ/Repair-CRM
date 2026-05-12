<?php
require_once __DIR__ . '/../includes/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$username = trim((string)(getenv('CRM_ADMIN_USERNAME') ?: ''));
$password = (string)(getenv('CRM_ADMIN_PASSWORD') ?: '');
$fullName = trim((string)(getenv('CRM_ADMIN_FULL_NAME') ?: 'Administrator'));

if ($username === '' || $password === '') {
    fwrite(STDERR, "Set CRM_ADMIN_USERNAME and CRM_ADMIN_PASSWORD before running this script.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "CRM_ADMIN_PASSWORD must be at least 12 characters.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password, full_name, role)
     VALUES (?, ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password = VALUES(password), full_name = VALUES(full_name), role = 'admin'"
);
$stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);

echo "Admin account created or updated.\n";
