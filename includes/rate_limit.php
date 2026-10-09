<?php
/**
 * API Rate Limiter
 * ----------------
 * Call checkApiRateLimit() at the top of any API endpoint.
 * Requires the `rate_limits` table (created by migrations/001_bootstrap.sql).
 * Store failures fail closed (503); only a missing table/column is allowed and logged.
 */

/**
 * Enforce a sliding-window rate limit.
 *
 * @param string $action          Unique name for this action (e.g. 'login', 'order_update').
 * @param int    $max_requests    Maximum number of requests allowed in the window.
 * @param int    $window_seconds  The time window in seconds.
 */
function checkApiRateLimit(string $action = 'api', int $max_requests = 60, int $window_seconds = 60): void {
    global $pdo;

    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key = $action . ':' . $ip;

    try {
        // Count requests in the current window
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rate_limits
            WHERE action_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$key, $window_seconds]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= $max_requests) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $window_seconds);
            echo json_encode([
                'success' => false,
                'message' => 'Too many requests. Please slow down.',
            ]);
            exit;
        }

        // Record this request
        $pdo->prepare("INSERT INTO rate_limits (action_key, ip) VALUES (?, ?)")
            ->execute([$key, $ip]);

        // Periodically purge old records (approx. once every 100 requests)
        if (random_int(1, 100) === 1) {
            // Housekeeping only: a failed purge must not reject a request that was already allowed.
            try {
                $pdo->exec("DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            } catch (Throwable $purgeError) {
                error_log('Rate limiter purge failed: ' . $purgeError->getMessage());
            }
        }
    } catch (Throwable $e) {
        // Only the known pre-migration schema gap degrades to "allow" (and is logged);
        // any other store failure fails closed so the limiter cannot silently disappear.
        $code = (string)$e->getCode();
        if (in_array($code, ['42S02', '42S22'], true)) {
            error_log('Rate limiter schema unavailable; allowing until CLI migration: ' . $e->getMessage());
            return;
        }
        error_log('Rate limiter error (blocking request): ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 30');
        echo json_encode(['success' => false, 'message' => 'Service temporarily unavailable.']);
        exit;
    }
}
