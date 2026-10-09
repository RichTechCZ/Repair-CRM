<?php
// Use the shared session configuration (strict mode, cookie flags) instead of a bare session_start().
require_once __DIR__ . '/includes/config.php';

// Logout is a state change: POST + CSRF only, so a cross-site link or image cannot end the session.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    header('Location: ' . (empty($_SESSION['user_id']) ? 'login.php' : 'index.php'));
    exit;
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
}
session_destroy();

header('Location: login.php');
exit;
