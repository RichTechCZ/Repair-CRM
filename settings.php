<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/telegram_webhook_security.php';

// Processing before header to avoid header already sent
$is_admin_check = (($_SESSION['role'] ?? '') == 'admin') || (hasPermission('admin_access'));

if (isset($_POST['set_lang']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $allowed_langs = ['ru', 'cs'];
    $new_lang = in_array($_POST['lang'] ?? '', $allowed_langs, true) ? $_POST['lang'] : 'ru';
    $_SESSION['lang'] = $new_lang;
    set_setting('language', $new_lang);
    header("Location: settings.php?tab=system");
    exit;
}

if (isset($_POST['update_company']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    set_setting('company_name', $_POST['company_name']);
    set_setting('company_address', $_POST['company_address']);
    set_setting('company_phone', $_POST['company_phone']);
    set_setting('currency', $_POST['currency']);
    header("Location: settings.php?tab=company&updated=1");
    exit;
}

if (isset($_POST['update_integrations']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $incoming_tg = trim((string)($_POST['tg_bot_token'] ?? ''));
    $incoming_ai = trim((string)($_POST['ai_api_key'] ?? ''));
    // Never echo secrets back into HTML; update only when a non-empty value is submitted.
    if ($incoming_tg !== '') {
        set_setting('tg_bot_token', $incoming_tg);
    }
    set_setting('ai_provider', $_POST['ai_provider'] ?? '');
    if ($incoming_ai !== '') {
        set_setting('ai_api_key', $incoming_ai);
    }
    set_setting('ai_model', $_POST['ai_model'] ?? '');

    if ($incoming_tg !== '') {
        // The webhook must be registered with the same secret_token that tg_webhook.php
        // enforces; otherwise Telegram stops sending the header and every update is rejected.
        $webhook_url = trim((string)(getenv('TELEGRAM_WEBHOOK_URL') ?: ''));
        $webhook_secret = trim((string)(getenv('TELEGRAM_WEBHOOK_SECRET') ?: ''));
        if (
            function_exists('curl_init')
            && isValidTelegramWebhookUrl($webhook_url)
            && isValidTelegramWebhookSecretFormat($webhook_secret)
        ) {
            $ch = curl_init('https://api.telegram.org/bot' . $incoming_tg . '/setWebhook');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'url' => $webhook_url,
                    'secret_token' => $webhook_secret,
                    'allowed_updates' => json_encode(['message', 'callback_query']),
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $tg_response = curl_exec($ch);
            unset($ch);
            $tg_result = is_string($tg_response) ? json_decode($tg_response, true) : null;
            if (!is_array($tg_result) || empty($tg_result['ok'])) {
                error_log('settings.php: Telegram setWebhook failed.');
            }
        } else {
            error_log('settings.php: Telegram webhook not re-registered; TELEGRAM_WEBHOOK_URL/SECRET are not configured.');
        }
    }
    header("Location: settings.php?tab=integrations&updated=1");
    exit;
}

if (isset($_POST['add_tech']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $name = $_POST['tech_name'];
    $email = $_POST['tech_email'] ?? '';
    $phone = $_POST['tech_phone'] ?? '';
    $spec = $_POST['tech_spec'];
    $role = in_array($_POST['role'] ?? '', ['engineer', 'manager'], true)
        ? $_POST['role']
        : 'engineer';
    $tg_id = trim((string)($_POST['tech_tg'] ?? ''));
    // Bot identity is bound to the immutable numeric Telegram user id only; @usernames can be re-registered by others.
    if ($tg_id !== '' && !preg_match('/^\d{3,20}$/', $tg_id)) { header("Location: settings.php?tab=staff&error=invalid_telegram_id"); exit; }
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';
    if ($password !== '' && strlen((string)$password) < 8) { header("Location: settings.php?tab=staff&error=short_password"); exit; }

    if (!empty($username)) {
        $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? UNION SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username, $username]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
    }
    $username_val = !empty($username) ? $username : null;
    $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : '';
    $stmt = $pdo->prepare("INSERT INTO technicians (name, email, phone, specialization, role, telegram_id, username, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $spec, $role, $tg_id, $username_val, $hashed_password]);
    header("Location: settings.php?tab=staff&tech_added=1");
    exit;
}

if (isset($_POST['edit_tech'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $id = $_POST['tech_id'];
    
    // Security check: technicians can only edit themselves
    if (!$is_admin_check && $id != ($_SESSION['tech_id'] ?? 0)) {
        header("Location: settings.php?tab=staff&error=unauthorized");
        exit;
    }

    $name = $_POST['tech_name'];
    $email = $_POST['tech_email'] ?? '';
    $phone = $_POST['tech_phone'] ?? '';
    $spec = $_POST['tech_spec'];
    $role = in_array($_POST['role'] ?? '', ['engineer', 'manager'], true)
        ? $_POST['role']
        : 'engineer';
    $tg_id = trim((string)($_POST['tech_tg'] ?? ''));
    // Bot identity is bound to the immutable numeric Telegram user id only; @usernames can be re-registered by others.
    // An unchanged legacy @username is kept so other fields stay editable (the list flags it; the bot ignores it).
    if ($tg_id !== '' && !preg_match('/^\d{3,20}$/', $tg_id)) {
        $legacyTgStmt = $pdo->prepare('SELECT telegram_id FROM technicians WHERE id = ?');
        $legacyTgStmt->execute([$id]);
        if ($tg_id !== (string)$legacyTgStmt->fetchColumn()) { header("Location: settings.php?tab=staff&error=invalid_telegram_id"); exit; }
    }
    $active = isset($_POST['is_active']) ? 1 : 0;
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';
    if ($password !== '' && strlen((string)$password) < 8) { header("Location: settings.php?tab=staff&error=short_password"); exit; }
    $engineer_rate = floatval($_POST['engineer_rate'] ?? 50);

    // Re-verify important fields if NOT admin
    if (!$is_admin_check) {
        $stmt = $pdo->prepare("SELECT role, is_active, username, engineer_rate, name, specialization FROM technicians WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        $role = $current['role'];
        $active = $current['is_active'];
        $username = $current['username'];
        $engineer_rate = $current['engineer_rate'];
        $name = $current['name'];
        $spec = $current['specialization'];
    }

    if (!empty($username) && $is_admin_check) { // Only admin can change username or check it
        $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
    }
    
    $username_val = !empty($username) ? $username : null;
    if (!empty($password)) {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, telegram_id = ?, is_active = ?, username = ?, password = ?, engineer_rate = ? WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $tg_id, $active, $username_val, password_hash($password, PASSWORD_DEFAULT), $engineer_rate, $id];
    } else {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, telegram_id = ?, is_active = ?, username = ?, engineer_rate = ? WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $tg_id, $active, $username_val, $engineer_rate, $id];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    header("Location: settings.php?tab=staff&updated=1");
    exit;
}

if (isset($_POST['delete_tech']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        header("Location: settings.php?tab=staff&error=csrf");
        exit;
    }
    $techOrders = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE technician_id = ?");
    $techOrders->execute([(int)$_POST['delete_tech']]);
    if ((int)$techOrders->fetchColumn() > 0) {
        // Deleting would orphan orders (technician_id -> NULL) and erase payroll history; deactivate instead.
        header("Location: settings.php?tab=staff&error=tech_has_orders");
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([(int)$_POST['delete_tech']]);
    header("Location: settings.php?tab=staff&tech_deleted=1");
    exit;
}

if (isset($_POST['save_permissions']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    setTechPermissions((int)($_POST['tech_id'] ?? 0), $_POST['permissions'] ?? []);
    header("Location: settings.php?tab=staff&perms_updated=1");
    exit;
}

// Administrator (users table) passwords: only an administrator session, re-authenticated with its
// own current password, and every change is written to the audit log (system_errors, type audit).
if (isset($_POST['change_admin_password'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header("Location: settings.php?tab=staff&error=unauthorized");
        exit;
    }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $actorStmt = $pdo->prepare('SELECT username, password FROM users WHERE id = ?');
    $actorStmt->execute([(int)$_SESSION['user_id']]);
    $actor = $actorStmt->fetch();
    if (!$actor || !password_verify((string)($_POST['current_password'] ?? ''), (string)$actor['password'])) {
        log_error('Admin password change rejected: wrong current password', 'audit', 'actor=' . ($actor['username'] ?? '?') . ' target_id=' . (int)($_POST['admin_id'] ?? 0));
        header("Location: settings.php?tab=admins&error=wrong_current_password");
        exit;
    }
    if (strlen((string)($_POST['new_password'] ?? '')) >= 8 && (int)($_POST['admin_id'] ?? 0) > 0) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, (int)$_POST['admin_id']]);
        log_error('Admin password changed', 'audit', 'actor=' . $actor['username'] . ' target_id=' . (int)$_POST['admin_id'] . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        
        if ((int)$_POST['admin_id'] === (int)$_SESSION['user_id'] && $_SESSION['role'] === 'admin') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header("Location: login.php");
            exit;
        }
        
        header("Location: settings.php?tab=admins&admin_pwd_updated=1");
    } else { header("Location: settings.php?tab=admins&error=short_password"); }
    exit;
}

if (isset($_POST['clear_logs']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    try { $pdo->query("DELETE FROM system_errors"); } catch (Exception $e) {}
    header("Location: settings.php?tab=system&logs_cleared=1");
    exit;
}

if (isset($_POST['update_system_settings']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $templates = trim($_POST['order_templates'] ?? '');
    $note_templates = trim($_POST['order_note_templates'] ?? '');
    $sla_new = max(0, (int)($_POST['sla_new_hours'] ?? 24));
    $sla_progress = max(0, (int)($_POST['sla_progress_hours'] ?? 72));
    set_setting('order_templates', $templates);
    set_setting('order_note_templates', $note_templates);
    set_setting('sla_new_hours', $sla_new);
    set_setting('sla_progress_hours', $sla_progress);
    header("Location: settings.php?tab=system&updated=1");
    exit;
}

$is_admin_user = hasPermission('admin_access');
$is_super_admin = (($_SESSION['role'] ?? '') === 'admin');

$active_tab = $_GET['tab'] ?? ($is_admin_user ? 'company' : 'staff');

// Security for technicians
if (!$is_admin_user) {
    if ($active_tab == 'company' || $active_tab == 'integrations' || $active_tab == 'system' || $active_tab == 'admins' || $active_tab == 'updates') {
        $active_tab = 'staff';
    }
} elseif (!$is_super_admin && $active_tab === 'admins') {
    $active_tab = 'staff';
}

// One-shot cleanup of obsolete permission rows (view_all_orders, edit_orders, …)
if ($is_admin_user && $active_tab === 'staff') {
    purgeObsoleteTechPermissions();
}

require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="page-header">
        <div class="page-header__copy">
            <div class="page-kicker"><?php echo e(get_setting('company_name', 'Repair CRM')); ?></div>
            <h1><?php echo __('settings'); ?></h1>
            <p class="page-subtitle"><?php echo e($_SESSION['full_name'] ?? ''); ?></p>
        </div>
        <?php
        $settings_success_keys = [
            'updated' => 'updated_success',
            'tech_added' => 'settings_tech_added',
            'tech_deleted' => 'settings_tech_deleted',
            'perms_updated' => 'settings_perms_updated',
            'admin_pwd_updated' => 'settings_admin_pwd_updated',
            'logs_cleared' => 'settings_logs_cleared',
        ];
        $settings_success_msg = null;
        foreach ($settings_success_keys as $param => $langKey) {
            if (isset($_GET[$param])) {
                $settings_success_msg = __($langKey);
                break;
            }
        }
        if ($settings_success_msg !== null): ?>
            <div class="page-actions">
                <span class="status-pill status-pill--stock-ok"><?php echo e($settings_success_msg); ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $settings_error_keys = [
        'username_taken' => 'settings_err_username_taken',
        'short_password' => 'settings_err_short_password',
        'tech_has_orders' => 'settings_err_tech_has_orders',
        'invalid_telegram_id' => 'settings_err_invalid_telegram_id',
        'wrong_current_password' => 'settings_err_wrong_current_password',
        'csrf' => 'csrf_invalid',
        'unauthorized' => 'access_denied_msg',
    ];
    $settings_error = $settings_error_keys[$_GET['error'] ?? ''] ?? null;
    if ($settings_error): ?>
        <div class="alert alert-danger" role="alert"><?php echo e(__($settings_error)); ?></div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary ui-ready" id="settingsTabs">
        <?php if ($is_admin_user): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'company' ? 'active' : 'text-white-75'; ?>" href="?tab=company"><i class="fas fa-building me-2"></i><?php echo __('company_data'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'integrations' ? 'active' : 'text-white-75'; ?>" href="?tab=integrations"><i class="fas fa-plug me-2"></i><?php echo __('integrations_tab'); ?></a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'staff' ? 'active' : 'text-white-75'; ?>" href="?tab=staff"><i class="fas fa-users me-2"></i><?php echo __('staff_tab'); ?></a>
        </li>
        <?php if ($is_admin_user): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'system' ? 'active' : 'text-white-75'; ?>" href="?tab=system"><i class="fas fa-server me-2"></i><?php echo __('system_db'); ?></a>
        </li>
        <?php if ($is_super_admin): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'admins' ? 'active' : 'text-white-75'; ?>" href="?tab=admins"><i class="fas fa-user-shield me-2"></i><?php echo __('admin_tab'); ?></a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'updates' ? 'active' : 'text-white-75'; ?>" href="?tab=updates"><i class="fas fa-cloud-download-alt me-2"></i><?php echo __('updates_tab'); ?></a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="glass-panel p-4 mb-4 tab-content">
        
        <!-- COMPANY DATA TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'company' ? 'show active' : ''; ?>">
            <div class="row">
                <div class="col-md-8">
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label text-white-75 small"><?php echo __('company_name'); ?></label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars(get_setting('company_name')); ?>">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-white-75 small"><?php echo __('company_address'); ?></label>
                                <textarea name="company_address" class="form-control" rows="3"><?php echo htmlspecialchars(get_setting('company_address')); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('company_phone'); ?></label>
                                <input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars(get_setting('company_phone')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('currency'); ?></label>
                                <input type="text" name="currency" class="form-control" value="<?php echo htmlspecialchars(get_setting('currency')); ?>">
                            </div>
                            <div class="col-12 mt-4 pt-3 border-top border-secondary">
                                <button type="submit" name="update_company" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- INTEGRATIONS TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'integrations' ? 'show active' : ''; ?>">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="row g-4">
                    <div class="col-md-6 border-end border-secondary">
                        <h5 class="mb-3 text-info"><i class="fab fa-telegram-plane me-2"></i>Telegram Bot</h5>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">API Bot Token</label>
                            <input type="password" name="tg_bot_token" class="form-control" value="" autocomplete="new-password" placeholder="<?php echo !empty(get_setting('tg_bot_token')) ? '********' : ''; ?>">
                        </div>
                        <div class="glass-panel p-3 border-secondary mb-3">
                            <h6 class="small fw-bold mb-2 text-white"><?php echo __('webhook_status'); ?></h6>
                            <?php 
                            $current_token = get_setting('tg_bot_token');
                            if (!empty($current_token) && $active_tab === 'integrations') {
                                $api_url = "https://api.telegram.org/bot" . $current_token . "/getWebhookInfo";
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, $api_url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                                $webhook_info = curl_exec($ch);
                                unset($ch);
                                $info = json_decode($webhook_info, true);
                                if ($info && $info['ok']) {
                                    $url = $info['result']['url'] ?: __('not_set');
                                    echo '<div class="small text-break text-white-75"><strong>URL:</strong> ' . htmlspecialchars($url) . '</div>';
                                } else { echo '<div class="small text-danger">'.__('token_invalid').'</div>'; }
                            } elseif (!empty($current_token)) {
                                echo '<div class="small text-muted">' . __('open_integrations_to_refresh_webhook') . '</div>';
                            } else { echo '<div class="small text-muted">'.__('token_not_set').'</div>'; }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="mb-3 text-primary"><i class="fas fa-robot me-2"></i><?php echo __('ai_integration'); ?></h5>
                        <div class="mb-3">
                            <label class="form-label small text-white-75"><?php echo __('provider'); ?></label>
                            <select name="ai_provider" class="form-select">
                                <option value="openrouter" <?php echo get_setting('ai_provider') == 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option>
                                <option value="openai" <?php echo get_setting('ai_provider') == 'openai' ? 'selected' : ''; ?>>OpenAI API</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">API Key</label>
                            <input type="password" name="ai_api_key" class="form-control" value="" autocomplete="new-password" placeholder="<?php echo !empty(get_setting('ai_api_key')) ? '********' : 'sk-...'; ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">AI Model</label>
                            <input type="text" name="ai_model" class="form-control" value="<?php echo htmlspecialchars(get_setting('ai_model', 'google/gemini-2.0-flash-001')); ?>">
                        </div>
                        <div class="form-text small text-white-75"><?php echo __('ai_hint'); ?></div>
                    </div>
                    <div class="col-12 border-top border-secondary pt-3">
                        <button type="submit" name="update_integrations" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- STAFF TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'staff' ? 'show active' : ''; ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo __('staff_and_techs'); ?></h5>
                <?php if ($is_admin_user): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTechModal"><i class="fas fa-plus me-1"></i> <?php echo __('add_btn'); ?></button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border-secondary table-mobile-cards">
                    <thead class="table-dark"><tr><th><?php echo __('name_col'); ?></th><th><?php echo __('login_col'); ?></th><th><?php echo __('role_col'); ?></th><th><?php echo __('spec_col'); ?></th><th>Telegram</th><th><?php echo __('status_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php 
                        $techs_query = $is_admin_user ? "SELECT * FROM technicians ORDER BY name ASC" : "SELECT * FROM technicians WHERE id = " . intval($_SESSION['tech_id'] ?? 0);
                        $techs = $pdo->query($techs_query)->fetchAll();
                        foreach ($techs as $t): ?>
                        <tr>
                            <td data-label="<?php echo e(__('name_col')); ?>"><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                            <td data-label="<?php echo e(__('login_col')); ?>">@<?php echo htmlspecialchars($t['username'] ?? '-'); ?></td>
                            <td data-label="<?php echo e(__('role_col')); ?>">
                                <?php 
                                $r = $t['role'] ?? 'engineer';
                                if($r == 'manager' || $r == 'admin') echo '<span class="status-pill status-pill--diagnostics">'.__('role_manager').'</span>';
                                else echo '<span class="status-pill status-pill--closed">'.__('role_engineer').'</span>';
                                ?>
                            </td>
                            <td data-label="<?php echo e(__('spec_col')); ?>"><span class="badge glass-panel text-white border-secondary"><?php echo e($t['specialization']); ?></span></td>
                            <td data-label="Telegram">
                                <?php if (!empty($t['telegram_id'])): ?>
                                    <code class="small"><?php echo htmlspecialchars($t['telegram_id']); ?></code>
                                    <?php if (!preg_match('/^\d+$/', (string)$t['telegram_id'])): ?>
                                        <span class="status-pill status-pill--overdue"><?php echo e(__('settings_err_invalid_telegram_id')); ?></span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-info" title="<?php echo e(__('test_notification')); ?>" aria-label="<?php echo e(__('test_notification') . ': ' . $t['name']); ?>" data-crm-action="test-technician-telegram" data-crm-id="<?php echo (int)$t['id']; ?>"><i class="fab fa-telegram-plane" aria-hidden="true"></i></button>
                                <?php else: ?>
                                    <span class="text-muted small"><?php echo __('not_linked'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo e(__('status_col')); ?>"><?php echo ($t['is_active'] ?? 1) ? '<span class="status-pill status-pill--stock-ok">'.__('active_status').'</span>' : '<span class="status-pill status-pill--closed">'.__('inactive_status').'</span>'; ?></td>
                            <td class="text-end" data-label="">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($is_admin_user): ?>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#permModal<?php echo $t['id']; ?>" aria-label="<?php echo e(__('permissions_title') . $t['name']); ?>"><i class="fas fa-shield-alt" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTechModal<?php echo $t['id']; ?>" aria-label="<?php echo e(__('edit_title') . $t['name']); ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                    <?php if ($is_admin_user): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="delete_tech" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" data-confirm="<?php echo e(__('delete_confirm')); ?>" aria-label="<?php echo e(__('delete') . ': ' . $t['name']); ?>"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SYSTEM & DB TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'system' ? 'show active' : ''; ?>">
            <div class="row g-4">
                <div class="col-md-6 border-end border-secondary">
                    <h5 class="mb-3 text-white"><i class="fas fa-database me-2 text-secondary"></i><?php echo __('database_header'); ?></h5>
                    <div class="d-grid gap-2 mb-4">
                        <button type="button" class="btn btn-primary" data-crm-action="run-backup"><i class="fas fa-file-download me-2" aria-hidden="true"></i><?php echo __('create_backup'); ?></button>
                        <div id="backupResult" class="small"></div>
                    </div>
                    <h5 class="mb-3 text-white"><i class="fas fa-globe me-2 text-info"></i><?php echo __('system_langs'); ?></h5>
                    <form method="POST" class="row g-2 align-items-center">
                        <?php echo csrfField(); ?>
                        <div class="col-auto">
                            <select name="lang" class="form-select bg-dark text-white border-secondary">
                                <option value="ru" <?php echo ($_SESSION['lang'] ?? 'ru') == 'ru' ? 'selected' : ''; ?>>Русский (RU)</option>
                                <option value="cs" <?php echo ($_SESSION['lang'] ?? 'ru') == 'cs' ? 'selected' : ''; ?>>Čeština (CS)</option>
                            </select>
                        </div>
                        <div class="col-auto"><button type="submit" name="set_lang" class="btn btn-primary"><?php echo __('save'); ?></button></div>
                    </form>

                    <hr class="my-4 border-secondary">
                    <h5 class="mb-3 text-white"><i class="fas fa-sliders-h me-2 text-primary"></i><?php echo __('system_settings'); ?></h5>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="mb-3">
                            <label class="form-label text-white-75 small"><?php echo __('templates'); ?></label>
                            <textarea name="order_templates" class="form-control" rows="5" placeholder="<?php echo __('templates_help'); ?>"><?php echo htmlspecialchars(get_setting('order_templates', '')); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-75 small"><?php echo __('templates_notes'); ?></label>
                            <textarea name="order_note_templates" class="form-control" rows="5" placeholder="<?php echo __('templates_help'); ?>"><?php echo htmlspecialchars(get_setting('order_note_templates', '')); ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('sla_new_hours'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="sla_new_hours" class="form-control" min="0" value="<?php echo (int)get_setting('sla_new_hours', 24); ?>">
                                    <span class="input-group-text bg-dark text-white border-secondary"><?php echo __('sla_hours'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('sla_progress_hours'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="sla_progress_hours" class="form-control" min="0" value="<?php echo (int)get_setting('sla_progress_hours', 72); ?>">
                                    <span class="input-group-text bg-dark text-white border-secondary"><?php echo __('sla_hours'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-text small mt-2"><?php echo __('sla_hint'); ?></div>
                        <div class="mt-3">
                            <button type="submit" name="update_system_settings" class="btn btn-primary"><?php echo __('save'); ?></button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo __('error_logs'); ?></h5>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <button type="submit" name="clear_logs" class="btn btn-sm btn-outline-danger" data-confirm="<?php echo __('clear_confirm'); ?>"><?php echo __('clear_btn'); ?></button></form>
                    </div>
                    <div class="overflow-auto border-secondary glass-panel p-2" style="max-height: 480px; font-family: monospace; font-size: 0.75rem;">
                        <?php
                        try {
                            $errors = $pdo->query("SELECT * FROM system_errors ORDER BY created_at DESC LIMIT 50")->fetchAll();
                        } catch (Exception $e) {
                            $errors = [];
                        }
                        if (empty($errors)) echo '<div class="text-success p-2">'.__('no_errors').'</div>';
                        foreach ($errors as $err): ?>
                            <div class="text-white border-bottom border-secondary mb-1 pb-1">
                                <span class="text-danger">[<?php echo $err['created_at']; ?>]</span> <strong><?php echo htmlspecialchars($err['error_type']); ?>:</strong> <?php echo htmlspecialchars($err['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ADMINS TAB -->
        <?php if ($is_super_admin): ?>
        <div class="tab-pane fade <?php echo $active_tab == 'admins' ? 'show active' : ''; ?>">
            <h5 class="mb-3"><?php echo __('admin_management_title'); ?></h5>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border-secondary table-mobile-cards">
                    <thead class="table-dark"><tr><th><?php echo __('login_col'); ?></th><th><?php echo __('name_col'); ?></th><th><?php echo __('role_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php $admins = $pdo->query("SELECT * FROM users ORDER BY role DESC")->fetchAll();
                        foreach ($admins as $admin): ?>
                        <tr>
                            <td data-label="<?php echo e(__('login_col')); ?>"><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                            <td data-label="<?php echo e(__('name_col')); ?>"><?php echo e($admin['full_name']); ?></td>
                            <td data-label="<?php echo e(__('role_col')); ?>"><span class="status-pill status-pill--priority-high">Admin</span></td>
                            <td class="text-end" data-label=""><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#adminPwdModal<?php echo $admin['id']; ?>"><i class="fas fa-key me-1" aria-hidden="true"></i> <?php echo __('password_btn'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_admin_user && $active_tab === 'updates'):
            require_once __DIR__ . '/includes/release_info.php';
            $installedRevision = crmInstalledRevision(__DIR__);
            $releaseTarget = null;
            $migrationInventory = null;
            try {
                $releaseTarget = crmUpdateTarget(__DIR__);
                $migrationInventory = crmMigrationInventory($pdo, __DIR__);
            } catch (Throwable $error) {
                error_log('CRM updates tab: ' . $error->getMessage());
            }
        ?>
        <div class="tab-pane show active">
            <div class="row g-3 align-items-start">
                <div class="col-lg-7">
                    <section class="statistics-panel" aria-labelledby="release-title">
                        <header class="statistics-panel__head align-items-center flex-wrap">
                            <h2 id="release-title"><?= e(__('updates_title')) ?></h2>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCheckUpdates" data-crm-action="check-updates"><?= e(__('check_updates')) ?></button>
                                <?php if ($releaseTarget): ?>
                                    <a class="btn btn-primary btn-sm" href="<?= e($releaseTarget['workflow']) ?>"><?= e(__('open_github_deploy')) ?></a>
                                <?php endif; ?>
                            </div>
                        </header>
                        <div class="p-3">
                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <div class="small text-white-75"><?= e(__('current_version')) ?></div>
                                    <strong><?= e($releaseTarget['version'] ?? '—') ?></strong>
                                    <code><?= e($installedRevision !== '' ? substr($installedRevision, 0, 7) : '—') ?></code>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-white-75"><?= e(__('github_target_version')) ?></div>
                                    <strong id="remoteVersion">—</strong>
                                    <code id="remoteRevision"></code>
                                </div>
                            </div>
                            <p class="small text-white-75 mb-2" id="updateStatusArea" role="status"><?= e(__('checking_updates')) ?></p>
                            <p class="small mb-2"><a id="deploymentRun" hidden></a><span id="deploymentDetails"></span></p>
                            <p class="small text-white-75 mb-0"><?= e(__('github_release_instructions')) ?></p>
                        </div>
                    </section>
                    <section class="statistics-panel mt-3" aria-labelledby="release-history">
                        <header class="statistics-panel__head"><h2 id="release-history"><?= e(__('changelog_title')) ?></h2></header>
                        <ul class="list-group list-group-flush" id="changelogArea"></ul>
                    </section>
                </div>
                <div class="col-lg-5">
                    <section class="statistics-panel" aria-labelledby="migration-title">
                        <header class="statistics-panel__head"><h2 id="migration-title"><?= e(__('release_migrations')) ?></h2></header>
                        <?php if ($migrationInventory === null): ?>
                            <p class="p-3 mb-0"><?= e(__('release_registry_unavailable')) ?></p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($migrationInventory['files'] as $name):
                                    $applied = in_array($name, $migrationInventory['applied'], true);
                                ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <code><?= e($name) ?></code>
                                        <span class="status-pill <?= $applied ? 'status-pill--ready' : 'status-pill--waiting' ?>"><?= e(__($applied ? 'release_migration_applied' : 'release_migration_pending')) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="p-3 border-top">
                            <p class="small text-white-75 mb-1" id="remoteMigrationsStatus"></p>
                            <ul id="remoteMigrations" class="small mb-0 ps-3"></ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="addTechModal" tabindex="-1" aria-labelledby="addTechModalTitle">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <div class="modal-header border-secondary"><h5 class="modal-title" id="addTechModalTitle"><?php echo __('add_employee_title'); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label text-white-75 small"><?php echo __('full_name_label'); ?></label><input type="text" name="tech_name" class="form-control" required></div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('crm_role_label'); ?></label>
                    <select name="role" class="form-select">
                        <option value="engineer"><?php echo __('role_engineer'); ?></option>
                        <option value="manager"><?php echo __('role_manager'); ?></option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <input type="text" name="tech_spec" class="form-control" placeholder="<?php echo __('spec_placeholder'); ?>">
                </div>
            </div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="tech_email" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('phone_label'); ?></label><input type="text" name="tech_phone" class="form-control"></div></div>
            <div class="mb-3">
                <label class="form-label">Telegram ID</label>
                <input type="text" name="tech_tg" class="form-control" placeholder="123456789">
                <div class="form-text small"><?php echo __('tg_notification_hint'); ?></div>
            </div>
            <hr><h6 class="mb-3"><?php echo __('system_access_header'); ?></h6>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('login_col'); ?></label><input type="text" name="tech_username" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('password_btn'); ?></label><input type="password" name="tech_password" class="form-control"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel_btn'); ?></button><button type="submit" name="add_tech" class="btn btn-primary"><?php echo __('create_btn'); ?></button></div>
    </form></div></div>
</div>

<?php foreach ($techs as $t): ?>
<div class="modal fade" id="editTechModal<?php echo (int)$t['id']; ?>" tabindex="-1" aria-labelledby="editTechModalTitle<?php echo (int)$t['id']; ?>">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary"><h5 class="modal-title" id="editTechModalTitle<?php echo (int)$t['id']; ?>"><?php echo __('edit_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button></div>
        <div class="modal-body">
            <?php if ($is_admin_user): ?>
            <div class="mb-3">
                <label class="form-label"><?php echo __('full_name_label'); ?></label>
                <input type="text" name="tech_name" class="form-control" value="<?php echo htmlspecialchars($t['name']); ?>" required>
            </div>
            <?php else: ?>
                <input type="hidden" name="tech_name" value="<?php echo htmlspecialchars($t['name']); ?>">
            <?php endif; ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('crm_role_label'); ?></label>
                    <?php if ($is_admin_user): ?>
                    <select name="role" class="form-select">
                        <option value="engineer" <?php echo ($t['role'] ?? 'engineer') == 'engineer' ? 'selected' : ''; ?>><?php echo __('role_engineer'); ?></option>
                        <option value="manager" <?php echo in_array(($t['role'] ?? 'engineer'), ['manager', 'admin'], true) ? 'selected' : ''; ?>><?php echo __('role_manager'); ?></option>
                    </select>
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo in_array(($t['role'] ?? 'engineer'), ['manager', 'admin'], true) ? __('role_manager') : __('role_engineer'); ?></div>
                        <input type="hidden" name="role" value="<?php echo in_array(($t['role'] ?? 'engineer'), ['manager', 'admin'], true) ? 'manager' : 'engineer'; ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <?php if ($is_admin_user): ?>
                        <input type="text" name="tech_spec" class="form-control" value="<?php echo htmlspecialchars($t['specialization'] ?? ''); ?>">
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo htmlspecialchars($t['specialization'] ?? ''); ?></div>
                        <input type="hidden" name="tech_spec" value="<?php echo htmlspecialchars($t['specialization'] ?? ''); ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label"><?php echo __('login_col'); ?></label>
                <?php if ($is_admin_user): ?>
                    <input type="text" name="tech_username" class="form-control" value="<?php echo htmlspecialchars($t['username'] ?? ''); ?>">
                <?php else: ?>
                    <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo htmlspecialchars($t['username'] ?? ''); ?></div>
                    <input type="hidden" name="tech_username" value="<?php echo htmlspecialchars($t['username'] ?? ''); ?>">
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label">Telegram ID</label>
                <div class="input-group">
                    <input type="text" name="tech_tg" class="form-control" value="<?php echo htmlspecialchars($t['telegram_id'] ?? ''); ?>" placeholder="123456789">
                    <button class="btn btn-outline-secondary" type="button" data-crm-action="test-technician-telegram" data-crm-id="<?php echo (int)$t['id']; ?>" aria-label="<?php echo e(__('test_notification')); ?>"><i class="fab fa-telegram-plane" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="mb-3"><label class="form-label"><?php echo __('new_password_label'); ?></label><input type="password" name="tech_password" class="form-control" placeholder="<?php echo __('password_placeholder'); ?>"></div>
            <?php if ($is_admin_user): ?>
            <div class="mb-3">
                <label class="form-label"><i class="fas fa-percentage me-1 text-success"></i><?php echo __('engineer_rate_label'); ?></label>
                <div class="input-group">
                    <input type="number" name="engineer_rate" class="form-control" step="0.01" min="0" max="100" value="<?php echo htmlspecialchars($t['engineer_rate'] ?? 50); ?>">
                    <span class="input-group-text">%</span>
                </div>
                <div class="form-text small"><?php echo __('rate_hint'); ?></div>
            </div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="isActive<?php echo $t['id']; ?>" <?php echo ($t['is_active'] ?? 1) ? 'checked' : ''; ?>><label class="form-check-label" for="isActive<?php echo $t['id']; ?>"><?php echo __('active_status'); ?></label></div>
            <?php else: ?>
                <input type="hidden" name="engineer_rate" value="<?php echo htmlspecialchars($t['engineer_rate'] ?? 50); ?>">
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="submit" name="edit_tech" class="btn btn-primary"><?php echo __('save'); ?></button></div>
    </form></div></div>
</div>

<div class="modal fade" id="permModal<?php echo (int)$t['id']; ?>" tabindex="-1" aria-labelledby="permModalTitle<?php echo (int)$t['id']; ?>">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary"><h5 class="modal-title" id="permModalTitle<?php echo (int)$t['id']; ?>"><?php echo __('permissions_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button></div>
        <div class="modal-body">
            <div class="alert alert-info border-0 bg-info bg-opacity-10 text-white-75 small mb-3">
                <i class="fas fa-info-circle me-1"></i><?php echo __('perm_orders_note'); ?>
            </div>
            <?php $tech_perms = getTechPermissions($t['id']); foreach (getAvailablePermissions() as $pk => $pi): ?>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $pk; ?>" id="p_<?php echo $t['id'].$pk; ?>" <?php echo in_array($pk, $tech_perms) ? 'checked' : ''; ?>><label class="form-check-label" for="p_<?php echo $t['id'].$pk; ?>"><strong><?php echo $pi['name']; ?></strong><div class="text-white-75 small"><?php echo $pi['desc']; ?></div></label></div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="save_permissions" class="btn btn-primary"><?php echo __('save_permissions_btn'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>

<?php if ($is_super_admin): ?>
<?php foreach ($admins as $admin): ?>
<div class="modal fade" id="adminPwdModal<?php echo (int)$admin['id']; ?>" tabindex="-1" aria-labelledby="adminPwdModalTitle<?php echo (int)$admin['id']; ?>">
    <div class="modal-dialog modal-sm"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
        <div class="modal-header border-secondary"><h6 class="modal-title" id="adminPwdModalTitle<?php echo (int)$admin['id']; ?>"><?php echo __('change_password_title'); ?></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button></div>
        <div class="modal-body">
            <label class="form-label small text-white-75" for="adminCurrentPwd<?php echo (int)$admin['id']; ?>"><?php echo __('your_current_password'); ?></label>
            <input type="password" name="current_password" id="adminCurrentPwd<?php echo (int)$admin['id']; ?>" class="form-control mb-2" required autocomplete="current-password">
            <label class="form-label small text-white-75" for="adminNewPwd<?php echo (int)$admin['id']; ?>"><?php echo __('new_password_for'); ?> <?php echo htmlspecialchars($admin['username']); ?></label>
            <input type="password" name="new_password" id="adminNewPwd<?php echo (int)$admin['id']; ?>" class="form-control" required minlength="8" autocomplete="new-password">
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="change_admin_password" class="btn btn-primary btn-sm"><?php echo __('save'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php $crmJsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE; ?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
function testTechTG(id) {
    if (!id) return;
    $.post('api/test_tech_tg.php', {id: id, csrf_token: $('meta[name="csrf-token"]').attr('content')}, function(res) {
        if (res && res.success) {
            showAlert(<?php echo json_encode(__('test_msg_sent'), $crmJsFlags); ?>);
        } else {
            showAlert((res && res.message) || <?php echo json_encode(__('error_js'), $crmJsFlags); ?>);
        }
    }).fail(function(xhr) {
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
            ? xhr.responseJSON.message
            : <?php echo json_encode(__('network_error'), $crmJsFlags); ?>;
        showAlert(msg);
    });
}

function triggerBackupDownload(path, filename) {
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var url = String(path || '');
    if (!url) return;
    if (url.indexOf('csrf_token=') === -1) {
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'csrf_token=' + encodeURIComponent(csrf);
    }
    if (filename && url.indexOf('file=') === -1) {
        url += '&file=' + encodeURIComponent(filename);
    }
    triggerDownload(url);
}

function runBackup() {
    const btn = document.querySelector('[data-crm-action="run-backup"]');
    const resultDiv = document.getElementById('backupResult');
    if (!btn || !resultDiv) return;

    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '...';

    $.post('api/backup_db.php', {csrf_token: $('meta[name="csrf-token"]').attr('content')})
        .done(function(res) {
            resultDiv.replaceChildren();
            const alert = document.createElement('div');
            alert.className = res.success
                ? 'alert alert-success p-2 mt-2'
                : 'alert alert-danger p-2 mt-2';
            alert.append(document.createTextNode(res.success ? <?php echo json_encode(__('done_js'), $crmJsFlags); ?> : (res.message || <?php echo json_encode(__('error_js'), $crmJsFlags); ?>)));
            if (res.success) {
                const link = document.createElement('a');
                link.href = '#';
                link.className = 'ms-2';
                link.textContent = String(res.filename || '');
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    triggerBackupDownload(String(res.path || res.download_url || ''), String(res.filename || ''));
                });
                alert.appendChild(link);
                triggerBackupDownload(String(res.path || res.download_url || ''), String(res.filename || ''));
            }
            resultDiv.appendChild(alert);
        })
        .fail(function(xhr) {
            resultDiv.replaceChildren();
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger p-2 mt-2';
            alert.textContent = (xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : <?php echo json_encode(__('error_js'), $crmJsFlags); ?>;
            resultDiv.appendChild(alert);
        })
        .always(function() {
            btn.disabled = false;
            btn.innerHTML = label;
        });
}
</script>

<?php if ($is_admin_user && $active_tab === 'updates'): ?>
<script nonce="<?= e(crmCspNonce()) ?>">
const releaseMessages = <?= json_encode([
    'checking' => __('checking_updates'),
    'error' => __('update_error'),
    'unknown' => __('release_compare_unknown'),
    'current' => __('release_compare_current'),
    'different' => __('release_compare_different'),
    'run' => __('release_run'),
    'running' => __('release_run_in_progress'),
    'success' => __('release_run_success'),
    'failure' => __('release_run_failure'),
    'noRun' => __('release_run_none'),
    'pending' => __('release_remote_pending'),
    'clear' => __('release_remote_clear'),
    'migrationsUnavailable' => __('release_remote_unavailable'),
    'noChangelog' => __('no_changelog'),
], $crmJsFlags) ?>;

async function checkForUpdates(force = false) {
    const button = document.getElementById('btnCheckUpdates');
    const status = document.getElementById('updateStatusArea');
    button.disabled = true;
    status.textContent = releaseMessages.checking;

    try {
        const response = await fetch('api/check_updates.php' + (force ? '?force=1' : ''));
        const info = await response.json();
        if (!response.ok || !info.success) throw new Error('GitHub update status unavailable');

        document.getElementById('remoteVersion').textContent = info.remote_version || '—';
        document.getElementById('remoteRevision').textContent = info.remote_revision.slice(0, 7);
        status.textContent = releaseMessages[info.comparison] || releaseMessages.unknown;
        const runLink = document.getElementById('deploymentRun');
        const run = info.deployment;
        runLink.hidden = !run;
        const runState = !run ? 'noRun' : run.status !== 'completed' ? 'running'
            : run.conclusion === 'success' ? 'success' : 'failure';
        if (run) {
            runLink.href = run.url;
            runLink.textContent = releaseMessages.run;
        }
        document.getElementById('deploymentDetails').textContent = run
            ? ' · ' + releaseMessages[runState] + ' · ' + run.revision.slice(0, 7)
            : releaseMessages.noRun;

        const log = document.getElementById('changelogArea');
        log.replaceChildren();
        const entries = info.changelog.length ? info.changelog : [{sha: '', message: releaseMessages.noChangelog}];
        for (const commit of entries) {
            const line = document.createElement('li');
            line.className = 'list-group-item small';
            const sha = document.createElement('code');
            sha.textContent = commit.sha;
            line.append(sha, ' ' + commit.message.split('\n')[0]);
            log.append(line);
        }

        const pending = info.pending_migrations;
        const list = document.getElementById('remoteMigrations');
        list.replaceChildren();
        document.getElementById('remoteMigrationsStatus').textContent = pending === null
            ? releaseMessages.migrationsUnavailable : pending.length ? releaseMessages.pending : releaseMessages.clear;
        for (const migration of pending || []) {
            const line = document.createElement('li');
            const name = document.createElement('code');
            name.textContent = migration;
            line.append(name);
            list.append(line);
        }
    } catch {
        status.textContent = releaseMessages.error;
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => checkForUpdates());
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

