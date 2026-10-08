<?php
/**
 * Shared API endpoint bootstrap.
 *
 * Usage (from api/*.php):
 *   require_once __DIR__ . '/../includes/api_bootstrap.php';
 *   api_bootstrap([
 *       'post' => true,                 // default true for mutators
 *       'csrf' => true,                 // default true when post
 *       'auth' => true,                 // default true
 *       'permission' => 'admin_access', // optional hasPermission() key
 *       'rate' => 'order_status',       // action name, or ['action'=>..., 'max'=>60, 'window'=>60]
 *       'json' => true,                 // default true
 *   ]);
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rate_limit.php';

/**
 * Initialize an API endpoint: buffering, JSON header, auth/CSRF/POST/rate/permission guards.
 *
 * @param array{
 *   auth?: bool,
 *   post?: bool,
 *   csrf?: bool,
 *   permission?: string|null,
 *   role?: string|null,              // require $_SESSION['role'] === value
 *   rate?: string|array{action:string,max?:int,window?:int}|null,
 *   json?: bool,                     // set JSON Content-Type + use api_json_exit for guards
 *   fail?: callable|null             // custom failure handler(message, status): void
 * } $options
 */
function api_bootstrap(array $options = []): void {
    static $booted = false;

    $auth = $options['auth'] ?? true;
    $post = $options['post'] ?? true;
    $csrf = array_key_exists('csrf', $options) ? (bool)$options['csrf'] : $post;
    $permission = $options['permission'] ?? null;
    $role = $options['role'] ?? null;
    $rate = $options['rate'] ?? null;
    $json = $options['json'] ?? true;
    $fail = $options['fail'] ?? null;

    $emitFail = static function (string $message, int $status) use ($json, $fail): void {
        if (is_callable($fail)) {
            $fail($message, $status);
            exit;
        }
        if ($json) {
            api_json_exit(['success' => false, 'message' => $message], $status);
        }
        http_response_code($status);
        die($message);
    };

    if (!$booted) {
        if (ob_get_level() === 0) {
            ob_start();
        }
        // Discard accidental whitespace/notices from includes before JSON body.
        if (ob_get_length()) {
            ob_clean();
        }
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $booted = true;
    } elseif ($json) {
        if (ob_get_length()) {
            ob_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
    }

    if ($rate !== null) {
        if (is_string($rate)) {
            checkApiRateLimit($rate, 60, 60);
        } elseif (is_array($rate)) {
            $action = (string)($rate['action'] ?? 'api');
            $max = (int)($rate['max'] ?? 60);
            $window = (int)($rate['window'] ?? 60);
            checkApiRateLimit($action, $max, $window);
        }
    }

    if ($auth && empty($_SESSION['user_id'])) {
        $emitFail(__('unauthorized'), 401);
    }

    if ($role !== null && $role !== '' && (($_SESSION['role'] ?? '') !== $role)) {
        $emitFail(__('access_denied_msg'), 403);
    }

    if ($permission !== null && $permission !== '' && !hasPermission($permission)) {
        $emitFail(__('unauthorized'), 403);
    }

    if ($post && (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST')) {
        $emitFail('Method not allowed', 405);
    }

    if ($csrf) {
        $token = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
        if (!validateCsrfToken($token)) {
            $emitFail(__('csrf_token_invalid'), 403);
        }
    }
}

/**
 * Emit JSON and stop the request.
 *
 * @param array<string,mixed> $payload
 */
function api_json_exit(array $payload, int $status = 200): void {
    if ($status !== 200) {
        http_response_code($status);
    }
    // Drop any accidental BOM/whitespace/notices so jQuery dataType:json never
    // treats a successful write as a "network error".
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
    );
    if ($json === false) {
        $json = '{"success":false,"message":"JSON encode failed"}';
    }
    echo $json;
    exit;
}

/**
 * Standard error response from an exception (safe message).
 */
function api_exception_exit(Throwable $e, int $status = 200): void {
    if ($status !== 200) {
        http_response_code($status);
    }
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)], $status === 200 ? 200 : $status);
}
