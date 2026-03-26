<?php
/**
 * CRM for Repair Service
 * Secure Configuration
 */

// ── Security Headers (sent before any output) ────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Session Security (must be set BEFORE session_start) ──────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

session_start();

// ── CSRF Token (generated once per session) ───────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true)));
    }
}

require_once __DIR__ . '/env_loader.php';
loadEnv(__DIR__ . '/../.env');

// ── Database ──────────────────────────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'repair_crm');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

require_once __DIR__ . '/lang.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    // Update last seen for technicians
    if (isset($_SESSION['tech_id'])) {
        $upd_stmt = $pdo->prepare("UPDATE technicians SET last_seen = NOW() WHERE id = ?");
        $upd_stmt->execute([$_SESSION['tech_id']]);
    }
} catch (PDOException $e) {
    error_log("DB Connection Error: " . $e->getMessage());
    $db_error = "Database connection failed. Please contact administrator.";
}

// ── Telegram token ────────────────────────────────────────────────────────────
if (isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tg_bot_token'");
        $stmt->execute();
        $token = $stmt->fetchColumn();
        if ($token) define('TG_BOT_TOKEN', $token);
    } catch (Exception $e) {}
}
if (!defined('TG_BOT_TOKEN')) {
    define('TG_BOT_TOKEN', '');
}

// ── Helper: safe output (XSS prevention) ─────────────────────────────────────
function e($str) {
    if ($str === null) $str = '';
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── Helper: CSRF validation ───────────────────────────────────────────────────
function validateCsrfToken($token) {
    return !empty($_SESSION['csrf_token'])
        && !empty($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Helper: CSRF input field (use in every form) ──────────────────────────────
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

// ── Helper: Return raw CSRF token value ────────────────────────────────────────
function generateCsrfToken(): string {
    return $_SESSION['csrf_token'] ?? '';
}
?>
