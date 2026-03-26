<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$error = false;

// ── Rate limiting ─────────────────────────────────────────────────────────────
function checkLoginAttempts($pdo) {
    if (!isset($pdo)) return true; // if DB down, allow (handled below)
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([$ip]);
        return $stmt->fetchColumn() < 5;
    } catch (Exception $e) {
        return true;
    }
}

function recordLoginAttempt($pdo, $success) {
    if (!isset($pdo)) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ($success) {
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())")->execute([$ip]);
        }
    } catch (Exception $e) {
        // login_attempts table may not exist yet — ignore
    }
}

// ── Login form handler ────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    // CSRF validation
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_invalid');
    } elseif (!checkLoginAttempts($pdo ?? null)) {
        $error = __('login_rate_limit');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (isset($pdo)) {
            // 1. Try Admin (users table)
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true); // Session Fixation protection
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = 'admin';
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['tech_id']   = null;
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                header("Location: index.php");
                exit;
            }

            // 2. Try Technician (technicians table)
            $stmt = $pdo->prepare("SELECT * FROM technicians WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $tech = $stmt->fetch();

            if ($tech && password_verify($password, $tech['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = 't' . $tech['id'];
                $_SESSION['username']  = $tech['username'];
                $_SESSION['role']      = (($tech['role'] ?? 'engineer') === 'admin') ? 'admin' : 'technician';
                $_SESSION['full_name'] = $tech['name'];
                $_SESSION['tech_id']   = $tech['id'];
                if ($_SESSION['role'] === 'technician') {
                    $_SESSION['internal_role'] = $tech['role'] ?? 'engineer';
                }
                invalidatePermissionsCache();
                recordLoginAttempt($pdo, true);
                header("Location: index.php");
                exit;
            }

            recordLoginAttempt($pdo, false);
            $error = __('login_error_auth');
        } else {
            $error = __('login_error_db');
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="login-card">
    <div class="glass-card shadow-sm p-2">
        <div class="card-body p-4 rounded text-white">
            <h3 class="text-center mb-4">Repair CRM</h3>

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
            <div class="mt-4 text-center text-muted small">
                <p><?php echo e(__('demo_access')); ?></p>
            </div>
        </div>
    </div>
</div>

</body>
</html>
