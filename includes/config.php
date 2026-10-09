<?php
/**
 * CRM for Repair Service
 * Secure Configuration
 */

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/request_security.php';
require_once __DIR__ . '/content_security_policy.php';
require_once __DIR__ . '/sensitive_data.php';
loadEnv(__DIR__ . '/../.env');
crmStartContentSecurityPolicy();

function crmConfigurationFailure(string $logMessage, string $publicMessage = 'Internal Server Error'): void
{
    error_log($logMessage);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $publicMessage . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    exit($publicMessage);
}

// ── Timezone ─────────────────────────────────────────────────────────────────
// Report periods compare PHP date() boundaries with MySQL NOW()/CURRENT_TIMESTAMP.
// CRM_TIMEZONE pins both to one zone (see PDO init below); unset keeps host defaults.
$crmTimezone = trim((string)(getenv('CRM_TIMEZONE') ?: ''));
if ($crmTimezone !== '') {
    if (!in_array($crmTimezone, timezone_identifiers_list(), true)) {
        crmConfigurationFailure('CRM_TIMEZONE is not a valid timezone identifier: ' . $crmTimezone);
    }
    date_default_timezone_set($crmTimezone);
}

// ── Security Headers (sent before any output) ────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

// ── Session Security (must be set BEFORE session_start) ──────────────────────
$trustProxyHttps = filter_var(getenv('CRM_TRUST_PROXY_HTTPS') ?: '0', FILTER_VALIDATE_BOOL);
$sessionUsesHttps = requestUsesHttps($_SERVER, $trustProxyHttps);
if ($sessionUsesHttps) {
    // Browsers must never downgrade the CRM (and its session cookie) to plain HTTP.
    header('Strict-Transport-Security: max-age=31536000');
}
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', $sessionUsesHttps ? 1 : 0);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 7200);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', 1);

// Machine endpoints (health checks, webhooks) define CRM_STATELESS before including this file:
// they carry no cookies, so starting a session would only create one orphan session file per hit.
if (defined('CRM_STATELESS')) {
    $_SESSION = [];
} else {
    session_start();
}

$sessionNow = time();
$idleTimeout = 2 * 60 * 60;
$absoluteTimeout = 12 * 60 * 60;
if (!empty($_SESSION['user_id'])) {
    $lastActivity = (int)($_SESSION['last_activity_at'] ?? $sessionNow);
    $createdAt = (int)($_SESSION['session_created_at'] ?? $sessionNow);
    if (($sessionNow - $lastActivity) > $idleTimeout || ($sessionNow - $createdAt) > $absoluteTimeout) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();
        session_start();
    }
}
$_SESSION['session_created_at'] = (int)($_SESSION['session_created_at'] ?? $sessionNow);
$_SESSION['last_activity_at'] = $sessionNow;

// ── CSRF Token (generated once per session) ───────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        crmConfigurationFailure(
            'CSRF Token Generation Failed: Cryptographically secure entropy source unavailable.',
            'Internal Server Error: Secure environment requirements not met.'
        );
    }
}

// ── Database ──────────────────────────────────────────────────────────────────
$crmEnvironment = strtolower(trim((string)(getenv('CRM_ENV') ?: 'development')));
$crmIsProduction = in_array($crmEnvironment, ['production', 'prod'], true);
if ($crmIsProduction && PHP_SAPI !== 'cli') {
    // Never rely on the host php.ini: stack traces and paths must not reach the browser.
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
$dbHost = trim((string)(getenv('DB_HOST') ?: ''));
$dbName = trim((string)(getenv('DB_NAME') ?: ''));
$dbUser = trim((string)(getenv('DB_USER') ?: ''));
$dbPass = (string)(getenv('DB_PASS') ?: '');

if ($crmIsProduction) {
    if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPass === '') {
        crmConfigurationFailure('Production database configuration is incomplete.');
    }
    if (in_array(strtolower($dbUser), ['root', 'admin', 'administrator'], true)) {
        crmConfigurationFailure('Privileged database accounts are forbidden in production.');
    }

    try {
        crmSensitiveDataKey();
    } catch (Throwable $e) {
        crmConfigurationFailure('Production sensitive-data key is invalid: ' . $e->getMessage());
    }
} else {
    $dbHost = $dbHost !== '' ? $dbHost : 'localhost';
    $dbName = $dbName !== '' ? $dbName : 'repair_crm';
    $dbUser = $dbUser !== '' ? $dbUser : 'root';
}

define('DB_HOST', $dbHost);
// Optional non-default port (empty = driver default 3306).
$dbPort = (int)(getenv('DB_PORT') ?: 0);
define('DB_PORT_DSN', $dbPort > 0 && $dbPort < 65536 ? ';port=' . $dbPort : '');
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);

require_once __DIR__ . '/lang.php';

try {
    $db_hosts = [DB_HOST];
    if (strtolower(DB_HOST) === 'localhost') {
        $db_hosts[] = '127.0.0.1';
    }

    $last_db_exception = null;
    foreach (array_unique($db_hosts) as $db_host) {
        try {
            $pdo = new PDO(
                "mysql:host=" . $db_host . DB_PORT_DSN . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            break;
        } catch (PDOException $connectionException) {
            $last_db_exception = $connectionException;
        }
    }

    if (!isset($pdo)) {
        throw $last_db_exception;
    }
    if ($crmTimezone !== '') {
        // Prefer the named zone (correct across DST); without MySQL tz tables fall back to the
        // numeric offset at request time.
        try {
            $pdo->exec("SET time_zone = " . $pdo->quote($crmTimezone));
        } catch (PDOException $timezoneError) {
            $pdo->exec("SET time_zone = " . $pdo->quote(date('P')));
        }
    }
} catch (PDOException $e) {
    crmConfigurationFailure('DB Connection Error: ' . $e->getMessage(), sprintf(__('db_error'), ''));
}

try {
    // Update last seen for technicians
    if (!empty($_SESSION['tech_id'])) {
        // A deactivated or deleted technician must lose access immediately, not at session expiry.
        $active_stmt = $pdo->prepare("SELECT is_active FROM technicians WHERE id = ?");
        $active_stmt->execute([$_SESSION['tech_id']]);
        $tech_is_active = $active_stmt->fetchColumn();
        if ($tech_is_active === false || (int)$tech_is_active !== 1) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['session_created_at'] = $sessionNow;
            $_SESSION['last_activity_at'] = $sessionNow;
        } elseif (($sessionNow - (int)($_SESSION['last_seen_written_at'] ?? 0)) >= 60) {
            // Presence needs minute precision; avoid a row write on every page/API hit.
            $upd_stmt = $pdo->prepare("UPDATE technicians SET last_seen = NOW() WHERE id = ?");
            $upd_stmt->execute([$_SESSION['tech_id']]);
            $_SESSION['last_seen_written_at'] = $sessionNow;
        }
    }
} catch (PDOException $e) {
    error_log("DB Session Update Error: " . $e->getMessage());
}

// ── Telegram token ────────────────────────────────────────────────────────────
$environmentTelegramToken = trim((string)(getenv('TG_BOT_TOKEN') ?: ''));
if ($environmentTelegramToken !== '') {
    define('TG_BOT_TOKEN', $environmentTelegramToken);
}
if (!defined('TG_BOT_TOKEN') && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tg_bot_token'");
        $stmt->execute();
        $token = $stmt->fetchColumn();
        if ($token) define('TG_BOT_TOKEN', $token);
    } catch (Exception $e) {
        error_log('Telegram token setting lookup failed: ' . $e->getMessage());
    }
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
