<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$error = false;

// ── Rate limiting ─────────────────────────────────────────────────────────────
const CRM_LOGIN_MAX_ATTEMPTS = 5;
// Per-account ceiling across all IPs. Higher than the per-IP limit so a stranger cannot
// lock the real administrator out with five guesses, while distributed guessing still stops.
const CRM_LOGIN_MAX_ACCOUNT_ATTEMPTS = 20;
const CRM_LOGIN_WINDOW_MINUTES = 5;

/**
 * A fresh production deploy can reach the login page before the CLI-only
 * schema migration has provisioned login_attempts. Treat only that known
 * schema gap as a temporary degraded mode; other store failures remain
 * fail-closed so the throttle cannot silently disappear on a live schema.
 */
function loginRateLimitSchemaUnavailable(Throwable $error): bool
{
    $code = (string)$error->getCode();
    if (in_array($code, ['42S02', '42S22'], true)) {
        return true;
    }

    $message = strtolower($error->getMessage());
    return strpos($message, 'login_attempts') !== false
        && (
            strpos($message, "doesn't exist") !== false
            || strpos($message, 'does not exist') !== false
            || strpos($message, 'unknown column') !== false
        );
}

/**
 * @return array{allowed:bool,retry_after:int}
 */
function getLoginRateLimitState($pdo, string $username) {
    $allowed = ['allowed' => true, 'retry_after' => 0];
    if (!($pdo instanceof PDO)) {
        return $allowed;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $usernameHash = hash('sha256', mb_strtolower(trim($username), 'UTF-8'));
    $windowMinutes = CRM_LOGIN_WINDOW_MINUTES;
    $maxAttempts = CRM_LOGIN_MAX_ATTEMPTS;

    try {
        // Drop expired rows so the sliding window stays accurate.
        $pdo->exec(
            'DELETE FROM login_attempts
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int)$windowMinutes . ' MINUTE)'
        );

        // Prefer scope with username_hash (migration 003). Fall back to IP-only
        // if the column is missing so a partial schema cannot lock everyone out.
        try {
            $stmt = $pdo->prepare(
                'SELECT SUM(ip = ?) AS ip_cnt,
                        SUM(username_hash = ?) AS user_cnt,
                        TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) AS age_seconds
                 FROM login_attempts
                 WHERE (ip = ? OR username_hash = ?)
                   AND created_at > DATE_SUB(NOW(), INTERVAL ' . (int)$windowMinutes . ' MINUTE)'
            );
            $stmt->execute([$ip, $usernameHash, $ip, $usernameHash]);
        } catch (Throwable $schemaError) {
            error_log('Login rate-limit scoped check failed, using IP-only: ' . $schemaError->getMessage());
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS ip_cnt, 0 AS user_cnt,
                        TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) AS age_seconds
                 FROM login_attempts
                 WHERE ip = ?
                   AND created_at > DATE_SUB(NOW(), INTERVAL ' . (int)$windowMinutes . ' MINUTE)'
            );
            $stmt->execute([$ip]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($row['ip_cnt'] ?? 0) < $maxAttempts && (int)($row['user_cnt'] ?? 0) < CRM_LOGIN_MAX_ACCOUNT_ATTEMPTS) {
            return $allowed;
        }

        $ageSeconds = max(0, (int)($row['age_seconds'] ?? 0));
        $windowSeconds = $windowMinutes * 60;
        $retryAfter = max(1, $windowSeconds - $ageSeconds);

        return ['allowed' => false, 'retry_after' => $retryAfter];
    } catch (Throwable $e) {
        if (loginRateLimitSchemaUnavailable($e)) {
            // The migration runner is CLI-only. Keep login usable until the
            // deployment can provision the store, while leaving an audit trail.
            error_log('Login rate-limit schema unavailable; allowing login until CLI migration: ' . $e->getMessage());
            return $allowed;
        }

        // Fail closed for runtime/permission/database failures on a provisioned store.
        error_log('Login rate-limit check failed (blocking attempt): ' . $e->getMessage());
        return false;
    }
}

function recordLoginAttempt($pdo, string $username, $success): void {
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $usernameHash = hash('sha256', mb_strtolower(trim($username), 'UTF-8'));
        if ($success) {
            try {
                $pdo->prepare('DELETE FROM login_attempts WHERE ip = ? OR username_hash = ?')
                    ->execute([$ip, $usernameHash]);
            } catch (Throwable $schemaError) {
                $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            }
            return;
        }

        try {
            $pdo->prepare('INSERT INTO login_attempts (ip, username_hash, created_at) VALUES (?, ?, NOW())')
                ->execute([$ip, $usernameHash]);
        } catch (Throwable $schemaError) {
            $pdo->prepare('INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())')
                ->execute([$ip]);
        }
    } catch (Throwable $e) {
        error_log('Login rate-limit record failed: ' . $e->getMessage());
    }
}

// ── Login form handler ────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // CSRF validation
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } else {
        $rateLimit = getLoginRateLimitState($pdo ?? null, $username);
        if ($rateLimit === false || (is_array($rateLimit) && empty($rateLimit['allowed']))) {
            $retryAfter = is_array($rateLimit) ? (int)($rateLimit['retry_after'] ?? (CRM_LOGIN_WINDOW_MINUTES * 60)) : (CRM_LOGIN_WINDOW_MINUTES * 60);
            $error = sprintf(__('login_rate_limit'), $retryAfter);
        } elseif (!isset($pdo)) {
            $error = __('login_error_db');
        } else {
            // 1. Try Admin (users table)
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();
            } catch (Throwable $e) {
                $user = false;
                error_log("login.php users query failed: " . $e->getMessage());
            }

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true); // Session Fixation protection
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // pre-login token must not survive authentication
                $_SESSION['session_created_at'] = time();
                $_SESSION['last_activity_at'] = time();
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = 'admin';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['tech_id']   = null;
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, $username, true);
                header("Location: index.php");
                exit;
            }

            // 2. Try Technician (technicians table)
            try {
                $stmt = $pdo->prepare("SELECT * FROM technicians WHERE username = ? AND is_active = 1");
                $stmt->execute([$username]);
                $tech = $stmt->fetch();
            } catch (Throwable $e) {
                $tech = false;
                error_log("login.php technicians query failed: " . $e->getMessage());
            }

            if ($tech && password_verify($password, $tech['password'])) {
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['session_created_at'] = time();
                $_SESSION['last_activity_at'] = time();
                $_SESSION['user_id']   = 't' . $tech['id'];
                $_SESSION['username']  = $tech['username'];
                // Administrative sessions are created exclusively from the
                // users table. Staff capabilities come from the explicit
                // technician permission allowlist.
                $_SESSION['role']      = 'technician';
                $_SESSION['full_name'] = $tech['name'];
                $_SESSION['tech_id']   = $tech['id'];
                $_SESSION['internal_role'] = in_array(($tech['role'] ?? ''), ['engineer', 'manager'], true)
                    ? $tech['role']
                    : 'manager';
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, $username, true);
                header("Location: index.php");
                exit;
            }

            if (!$user && !$tech) {
                // Same bcrypt cost as a real account, so response time does not reveal valid logins.
                password_verify($password, '$2y$10$b4vqRN1pW9yOcDI.Sy0OM.VLus.iicXRZ9hQFmtLss7DcalC1yWDG');
            }
            recordLoginAttempt($pdo, $username, false);
            $error = __('login_error_auth');
        }
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['lang'] ?? 'ru'); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__('login_title')); ?> - Repair CRM</title>
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="login-card">
    <div class="glass-card shadow-sm">
        <div class="card-body rounded text-white">
            <div class="login-brand">
                <div class="brand-mark" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div class="login-brand__copy">
                    <strong>Repair CRM</strong>
                    <span><?php echo e(__('login_title')); ?></span>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger small"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="mb-3">
                    <label class="form-label"><?php echo e(__('username_label')); ?></label>
                    <input type="text" name="username" class="form-control" required autofocus autocomplete="username">
                </div>
                <div class="mb-4">
                    <label class="form-label"><?php echo e(__('password')); ?></label>
                    <input type="password" name="password" class="form-control" required autocomplete="current-password">
                </div>
                <div class="d-grid">
                    <button type="submit" name="login" class="btn btn-primary"><?php echo e(__('login_btn')); ?></button>
                </div>
            </form>
            <div class="login-note">
                <p><?php echo e(__('demo_access')); ?></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
