<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Processing before header to avoid header already sent
$is_admin_check = (($_SESSION['role'] ?? '') == 'admin') || (hasPermission('admin_access'));

if (isset($_POST['set_lang']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $_SESSION['lang'] = $_POST['lang'];
    set_setting('language', $_POST['lang']);
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
    set_setting('tg_bot_token', trim($_POST['tg_bot_token']));
    set_setting('ai_provider', $_POST['ai_provider']);
    set_setting('ai_api_key', trim($_POST['ai_api_key']));
    set_setting('ai_model', $_POST['ai_model']);

    if (!empty($_POST['tg_bot_token'])) {
        $token = trim($_POST['tg_bot_token']);
        $webhook_url = "https://app.servis.expert/tg_webhook.php";
        $api_url = "https://api.telegram.org/bot" . $token . "/setWebhook?url=" . urlencode($webhook_url) . "&drop_pending_updates=true";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_exec($ch);
        curl_close($ch);
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
    $role = $_POST['role'] ?? 'engineer';
    $tg_id = $_POST['tech_tg'] ?? '';
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';

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
    $role = $_POST['role'] ?? 'engineer';
    $tg_id = $_POST['tech_tg'] ?? '';
    $active = isset($_POST['is_active']) ? 1 : 0;
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';
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
    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([$_POST['delete_tech']]);
    header("Location: settings.php?tab=staff");
    exit;
}

if (isset($_POST['save_permissions']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    setTechPermissions($_POST['tech_id'], $_POST['permissions'] ?? []);
    header("Location: settings.php?tab=staff&perms_updated=1");
    exit;
}

if (isset($_POST['change_admin_password']) && hasPermission('manage_passwords')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    if (strlen($_POST['new_password']) >= 8) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $_POST['admin_id']]);
        
        if ($_POST['admin_id'] == $_SESSION['user_id'] && $_SESSION['role'] === 'admin') {
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

$active_tab = $_GET['tab'] ?? ($is_admin_user ? 'company' : 'staff');

// Security for technicians
if (!$is_admin_user) {
    if ($active_tab == 'company' || $active_tab == 'integrations' || $active_tab == 'system' || $active_tab == 'admins' || $active_tab == 'updates') {
        $active_tab = 'staff';
    }
}
require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
        <h2 class="mb-0 text-white"><i class="fas fa-cog me-3 text-primary"></i><?php echo __('settings'); ?></h2>
        <?php if (isset($_GET['updated'])): ?>
            <span class="badge bg-success-glow"><?php echo __('updated_success'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary" id="settingsTabs">
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
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'admins' ? 'active' : 'text-white-75'; ?>" href="?tab=admins"><i class="fas fa-user-shield me-2"></i><?php echo __('admin_tab'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'updates' ? 'active' : 'text-white-75'; ?>" href="?tab=updates" id="updatesNavLink"><i class="fas fa-cloud-download-alt me-2"></i><?php echo __('updates_tab'); ?> <span id="updateBadgeNav" class="badge bg-warning text-dark ms-1" style="display:none;">!</span></a>
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
                            <input type="password" name="tg_bot_token" class="form-control" value="<?php echo htmlspecialchars(get_setting('tg_bot_token')); ?>">
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
                                curl_close($ch);
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
                            <input type="password" name="ai_api_key" class="form-control" value="<?php echo htmlspecialchars(get_setting('ai_api_key')); ?>" placeholder="sk-...">
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
                <table class="table table-dark table-hover align-middle border-secondary">
                    <thead class="table-dark"><tr><th><?php echo __('name_col'); ?></th><th><?php echo __('login_col'); ?></th><th><?php echo __('role_col'); ?></th><th><?php echo __('spec_col'); ?></th><th>Telegram</th><th><?php echo __('status_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php 
                        $techs_query = $is_admin_user ? "SELECT * FROM technicians ORDER BY name ASC" : "SELECT * FROM technicians WHERE id = " . intval($_SESSION['tech_id'] ?? 0);
                        $techs = $pdo->query($techs_query)->fetchAll();
                        foreach ($techs as $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                            <td>@<?php echo htmlspecialchars($t['username'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $r = $t['role'] ?? 'engineer';
                                if($r == 'admin') echo '<span class="badge bg-danger">'.__('role_admin').'</span>';
                                elseif($r == 'manager') echo '<span class="badge bg-primary">'.__('role_manager').'</span>';
                                else echo '<span class="badge bg-info-glow">'.__('role_engineer').'</span>';
                                ?>
                            </td>
                            <td><span class="badge glass-panel text-white border-secondary"><?php echo htmlspecialchars($t['specialization']); ?></span></td>
                            <td>
                                <?php if (!empty($t['telegram_id'])): ?>
                                    <code class="small"><?php echo htmlspecialchars($t['telegram_id']); ?></code>
                                    <button class="btn btn-link btn-sm p-0 ms-1 text-info" title="Тест уведомления" onclick="testTechTG(<?php echo $t['id']; ?>)"><i class="fab fa-telegram-plane"></i></button>
                                <?php else: ?>
                                    <span class="text-muted small"><?php echo __('not_linked'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($t['is_active'] ?? 1) ? '<span class="badge bg-success">'.__('active_status').'</span>' : '<span class="badge bg-secondary">'.__('inactive_status').'</span>'; ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($is_admin_user): ?>
                                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#permModal<?php echo $t['id']; ?>"><i class="fas fa-shield-alt"></i></button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTechModal<?php echo $t['id']; ?>"><i class="fas fa-edit"></i></button>
                                    <?php if ($is_admin_user): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="delete_tech" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" data-confirm="<?php echo __('delete_confirm'); ?>"><i class="fas fa-trash"></i></button>
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
                        <button type="button" class="btn btn-success" onclick="runBackup()"><i class="fas fa-file-download me-2"></i><?php echo __('create_backup'); ?></button>
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
        <?php if ($is_admin_user): ?>
        <div class="tab-pane fade <?php echo $active_tab == 'admins' ? 'show active' : ''; ?>">
            <h5 class="mb-3"><?php echo __('admin_management_title'); ?></h5>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border-secondary">
                    <thead class="table-dark"><tr><th><?php echo __('login_col'); ?></th><th><?php echo __('name_col'); ?></th><th><?php echo __('role_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php $admins = $pdo->query("SELECT * FROM users ORDER BY role DESC")->fetchAll();
                        foreach ($admins as $admin): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($admin['full_name']); ?></td>
                            <td><span class="badge bg-danger">Admin</span></td>
                            <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#adminPwdModal<?php echo $admin['id']; ?>"><i class="fas fa-key me-1"></i> <?php echo __('password_btn'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- UPDATES TAB -->
        <?php if ($is_admin_user): ?>
        <div class="tab-pane fade <?php echo $active_tab == 'updates' ? 'show active' : ''; ?>">
            <div class="row g-4">
                <!-- Left: Version & Update -->
                <div class="col-md-6">
                    <div class="glass-panel p-4 mb-4 border-secondary">
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-25 p-3 me-3">
                                <i class="fas fa-code-branch fa-lg text-primary"></i>
                            </div>
                            <div>
                                <h5 class="mb-1 text-white"><?php echo __('updates_title'); ?></h5>
                                <div class="text-white-75 small"><?php echo __('update_server_hint'); ?></div>
                            </div>
                        </div>

                        <?php
                        $versionFile = __DIR__ . '/version.json';
                        $localVer = file_exists($versionFile) ? json_decode(file_get_contents($versionFile), true) : null;
                        ?>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <div class="glass-panel p-3 text-center border-secondary">
                                    <div class="text-white-75 small mb-1"><?php echo __('current_version'); ?></div>
                                    <div class="h4 text-white mb-0" id="localVersion"><?php echo htmlspecialchars($localVer['version'] ?? '?.?.?'); ?></div>
                                    <div class="small text-muted"><?php echo __('build'); ?>: <?php echo (int)($localVer['build'] ?? 0); ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="glass-panel p-3 text-center border-secondary" id="remoteVersionPanel">
                                    <div class="text-white-75 small mb-1"><?php echo __('latest_version'); ?></div>
                                    <div class="h4 text-muted mb-0" id="remoteVersion">—</div>
                                    <div class="small text-muted" id="remoteReleaseDate"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Status area -->
                        <div id="updateStatusArea" class="mb-4" style="display:none;"></div>

                        <!-- Action buttons -->
                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-primary" id="btnCheckUpdates" onclick="checkForUpdates(true)">
                                <i class="fas fa-sync-alt me-2"></i><?php echo __('check_updates'); ?>
                            </button>
                            <button type="button" class="btn btn-success" id="btnInstallUpdate" style="display:none;" onclick="installUpdate()">
                                <i class="fas fa-cloud-download-alt me-2"></i><?php echo __('install_update'); ?>
                            </button>
                        </div>

                        <div class="alert alert-warning border-0 bg-warning bg-opacity-10 mt-3 mb-0 small">
                            <i class="fas fa-exclamation-triangle me-2 text-warning"></i><?php echo __('update_warning'); ?>
                        </div>
                    </div>
                </div>

                <!-- Right: Changelog -->
                <div class="col-md-6">
                    <div class="glass-panel p-4 border-secondary">
                        <h5 class="mb-3 text-white"><i class="fas fa-list-ul me-2 text-info"></i><?php echo __('changelog_title'); ?></h5>
                        <div id="changelogArea" class="overflow-auto" style="max-height: 480px;">
                            <div class="text-muted small"><?php echo __('no_changelog'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALS -->
<div class="modal fade" id="addTechModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <div class="modal-header border-secondary"><h5 class="modal-title"><?php echo __('add_employee_title'); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label text-white-75 small"><?php echo __('full_name_label'); ?></label><input type="text" name="tech_name" class="form-control" required></div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('crm_role_label'); ?></label>
                    <select name="role" class="form-select">
                        <option value="engineer"><?php echo __('role_engineer'); ?></option>
                        <option value="manager"><?php echo __('role_manager'); ?></option>
                        <option value="admin"><?php echo __('role_admin'); ?></option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <input type="text" name="tech_spec" class="form-control" placeholder="<?php echo __('spec_placeholder'); ?>">
                </div>
            </div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="tech_email" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('phone_label'); ?></label><input type="text" name="tech_phone" class="form-control"></div></div>
            <div class="mb-3">
                <label class="form-label">Telegram ID / Username</label>
                <input type="text" name="tech_tg" class="form-control" placeholder="123456789 или @username">
                <div class="form-text small"><?php echo __('tg_notification_hint'); ?></div>
            </div>
            <hr><h6 class="mb-3"><?php echo __('system_access_header'); ?></h6>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('login_col'); ?></label><input type="text" name="tech_username" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('password_btn'); ?></label><input type="password" name="tech_password" class="form-control"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel_btn'); ?></button><button type="submit" name="add_tech" class="btn btn-primary"><?php echo __('create_btn'); ?></button></div>
    </form></div></div>
</div>

<?php foreach ($techs as $t): ?>
<div class="modal fade" id="editTechModal<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary"><h5 class="modal-title"><?php echo __('edit_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
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
                        <option value="manager" <?php echo ($t['role'] ?? 'engineer') == 'manager' ? 'selected' : ''; ?>><?php echo __('role_manager'); ?></option>
                        <option value="admin" <?php echo ($t['role'] ?? 'engineer') == 'admin' ? 'selected' : ''; ?>><?php echo __('role_admin'); ?></option>
                    </select>
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo ($t['role'] ?? 'engineer') == 'admin' ? __('role_admin') : (($t['role'] ?? 'engineer') == 'manager' ? __('role_manager') : __('role_engineer')); ?></div>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($t['role'] ?? 'engineer'); ?>">
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
                <label class="form-label">Telegram ID / Username</label>
                <div class="input-group">
                    <input type="text" name="tech_tg" class="form-control" value="<?php echo htmlspecialchars($t['telegram_id'] ?? ''); ?>" placeholder="123456789 или @username">
                    <button class="btn btn-outline-info" type="button" onclick="testTechTG(<?php echo $t['id']; ?>)"><i class="fab fa-telegram-plane"></i></button>
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

<div class="modal fade" id="permModal<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary bg-warning bg-opacity-10"><h5 class="modal-title"><?php echo __('permissions_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php $tech_perms = getTechPermissions($t['id']); foreach (getAvailablePermissions() as $pk => $pi): ?>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $pk; ?>" id="p_<?php echo $t['id'].$pk; ?>" <?php echo in_array($pk, $tech_perms) ? 'checked' : ''; ?>><label class="form-check-label" for="p_<?php echo $t['id'].$pk; ?>"><strong><?php echo $pi['name']; ?></strong><div class="text-white-75 small"><?php echo $pi['desc']; ?></div></label></div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="save_permissions" class="btn btn-warning"><?php echo __('save_permissions_btn'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>

<?php if ($is_admin_user): ?>
<?php foreach ($admins as $admin): ?>
<div class="modal fade" id="adminPwdModal<?php echo $admin['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content glass-card border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
        <div class="modal-header border-secondary bg-danger bg-opacity-25 text-white"><h6 class="modal-title"><?php echo __('change_password_title'); ?></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label small text-white-75"><?php echo __('new_password_for'); ?> <?php echo htmlspecialchars($admin['username']); ?></label>
            <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="change_admin_password" class="btn btn-danger btn-sm"><?php echo __('save'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function testTechTG(id) { if (!id) return; $.post('api/test_tech_tg.php', {id: id, csrf_token: $('meta[name="csrf-token"]').attr('content')}, function(res) { if (res.success) { showAlert('OK'); } else { showAlert(res.message); } }); }
function runBackup() {
    const btn = event.target.closest('button'); const resultDiv = document.getElementById('backupResult');
    btn.disabled = true; btn.innerHTML = '...';
    $.post('api/backup_db.php', {csrf_token: $('meta[name="csrf-token"]').attr('content')}, function(res) {
        btn.disabled = false; btn.innerHTML = '<?php echo __('create_backup'); ?>';
        if (res.success) { resultDiv.innerHTML = `<div class="alert alert-success p-2 mt-2"><?php echo __('done_js'); ?><a href="javascript:void(0)" onclick="triggerDownload('${res.path}')">${res.filename}</a></div>`; triggerDownload(res.path); }
        else { resultDiv.innerHTML = `<div class="alert alert-danger p-2 mt-2"><?php echo __('error_js'); ?></div>`; }
    });
}
</script>

<?php if ($is_admin_user): ?>
<script>
const UPDATE_TRANSLATIONS = {
    check_updates:     '<?php echo __('check_updates'); ?>',
    checking_updates:  '<?php echo __('checking_updates'); ?>',
    install_update:    '<?php echo __('install_update'); ?>',
    installing_update: '<?php echo __('installing_update'); ?>',
    update_available:  '<?php echo __('update_available'); ?>',
    update_available_desc: '<?php echo __('update_available_desc'); ?>',
    up_to_date:        '<?php echo __('up_to_date'); ?>',
    up_to_date_desc:   '<?php echo __('up_to_date_desc'); ?>',
    update_success:    '<?php echo __('update_success'); ?>',
    update_error:      '<?php echo __('update_error'); ?>',
    no_changelog:      '<?php echo __('no_changelog'); ?>',
    last_check:        '<?php echo __('last_check'); ?>',
    minutes_ago:       '<?php echo __('minutes_ago'); ?>',
    migrations_ran:    '<?php echo __('migrations_ran'); ?>',
    release_date:      '<?php echo __('release_date'); ?>',
    build:             '<?php echo __('build'); ?>'
};

function checkForUpdates(force = false) {
    const btn = document.getElementById('btnCheckUpdates');
    const statusArea = document.getElementById('updateStatusArea');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + UPDATE_TRANSLATIONS.checking_updates;
    statusArea.style.display = 'none';

    const url = 'api/check_updates.php' + (force ? '?force=1' : '');
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>' + UPDATE_TRANSLATIONS.check_updates;
            
            if (!data.success) {
                statusArea.style.display = 'block';
                statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>${data.message || UPDATE_TRANSLATIONS.update_error}
                </div>`;
                return;
            }

            // Update remote version display
            const rv = document.getElementById('remoteVersion');
            rv.textContent = data.remote_version;
            rv.className = data.has_update ? 'h4 text-success mb-0' : 'h4 text-white mb-0';
            
            const rd = document.getElementById('remoteReleaseDate');
            if (data.release_date) {
                rd.textContent = UPDATE_TRANSLATIONS.release_date + ': ' + data.release_date;
            }

            // Status message
            statusArea.style.display = 'block';
            if (data.has_update) {
                statusArea.innerHTML = `<div class="alert alert-info border-0 bg-info bg-opacity-10 small mb-0">
                    <i class="fas fa-arrow-circle-up me-2 text-info"></i>
                    <strong>${UPDATE_TRANSLATIONS.update_available}</strong> v${data.local_version} → v${data.remote_version}
                    <div class="mt-1 text-white-75">${UPDATE_TRANSLATIONS.update_available_desc}</div>
                </div>`;
                document.getElementById('btnInstallUpdate').style.display = 'inline-block';
                // Show badge
                const badge = document.getElementById('updateBadgeNav');
                if (badge) badge.style.display = 'inline';
            } else {
                statusArea.innerHTML = `<div class="alert alert-success border-0 bg-success bg-opacity-10 small mb-0">
                    <i class="fas fa-check-circle me-2 text-success"></i>
                    <strong>${UPDATE_TRANSLATIONS.up_to_date}</strong> (v${data.local_version})
                    <div class="mt-1 text-white-75">${UPDATE_TRANSLATIONS.up_to_date_desc}</div>
                </div>`;
                document.getElementById('btnInstallUpdate').style.display = 'none';
            }

            // Cache info
            if (data.from_cache && data.cache_age) {
                const mins = Math.round(data.cache_age / 60);
                statusArea.innerHTML += `<div class="text-muted small mt-2"><i class="fas fa-clock me-1"></i>${UPDATE_TRANSLATIONS.last_check}: ${mins} ${UPDATE_TRANSLATIONS.minutes_ago}</div>`;
            }

            // Changelog
            renderChangelog(data.changelog || []);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>' + UPDATE_TRANSLATIONS.check_updates;
            statusArea.style.display = 'block';
            statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>${err.message || UPDATE_TRANSLATIONS.update_error}
            </div>`;
        });
}

function renderChangelog(commits) {
    const area = document.getElementById('changelogArea');
    if (!commits || commits.length === 0) {
        area.innerHTML = `<div class="text-muted small">${UPDATE_TRANSLATIONS.no_changelog}</div>`;
        return;
    }
    let html = '';
    commits.forEach(c => {
        const date = c.date ? new Date(c.date).toLocaleString() : '';
        const msg = (c.message || '').split('\n')[0]; // first line only
        html += `<div class="d-flex align-items-start mb-2 pb-2 border-bottom border-secondary">
            <code class="text-info me-2 flex-shrink-0" style="font-size:0.75rem;">${c.sha}</code>
            <div class="flex-grow-1">
                <div class="text-white small">${escapeHtml(msg)}</div>
                <div class="text-muted" style="font-size:0.7rem;">${date} · ${escapeHtml(c.author || '')}</div>
            </div>
        </div>`;
    });
    area.innerHTML = html;
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function installUpdate() {
    if (!confirm('<?php echo __('update_warning'); ?>  \n\nContinue?')) return;
    
    const btn = document.getElementById('btnInstallUpdate');
    const statusArea = document.getElementById('updateStatusArea');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + UPDATE_TRANSLATIONS.installing_update;

    const csrf = $('meta[name="csrf-token"]').attr('content') || '<?php echo generateCsrfToken(); ?>';
    
    fetch('api/run_update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(csrf)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>' + UPDATE_TRANSLATIONS.install_update;
        
        statusArea.style.display = 'block';
        if (data.success) {
            let migrationsHtml = '';
            if (data.migrations && data.migrations.length > 0) {
                migrationsHtml = `<div class="mt-2"><strong>${UPDATE_TRANSLATIONS.migrations_ran}:</strong><ul class="mb-0">`;
                data.migrations.forEach(m => {
                    migrationsHtml += `<li>${escapeHtml(m.file || '')} — ${m.status}</li>`;
                });
                migrationsHtml += '</ul></div>';
            }
            statusArea.innerHTML = `<div class="alert alert-success border-0 bg-success bg-opacity-10 small mb-0">
                <i class="fas fa-check-circle me-2 text-success"></i>
                <strong>${UPDATE_TRANSLATIONS.update_success}</strong>
                <div class="mt-1">v${data.previous_version} → v${data.new_version}</div>
                ${migrationsHtml}
                <div class="mt-2"><a href="settings.php?tab=updates" class="btn btn-sm btn-outline-light"><i class="fas fa-redo me-1"></i> Reload</a></div>
            </div>`;
            document.getElementById('btnInstallUpdate').style.display = 'none';
            const badge = document.getElementById('updateBadgeNav');
            if (badge) badge.style.display = 'none';
            // Update local version display
            document.getElementById('localVersion').textContent = data.new_version;
        } else {
            statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>${UPDATE_TRANSLATIONS.update_error}</strong>
                <div class="mt-1">${escapeHtml(data.message || '')}</div>
                ${data.output ? '<pre class="mt-2 mb-0 text-white-75 small">' + escapeHtml(data.output) + '</pre>' : ''}
                ${data.hint ? '<div class="mt-2 text-info"><i class="fas fa-lightbulb me-1"></i>' + escapeHtml(data.hint) + '</div>' : ''}
            </div>`;
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>' + UPDATE_TRANSLATIONS.install_update;
        statusArea.style.display = 'block';
        statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
            <i class="fas fa-exclamation-circle me-2"></i>${err.message || UPDATE_TRANSLATIONS.update_error}
        </div>`;
    });
}

// Auto-check on page load (use cache, no force)
document.addEventListener('DOMContentLoaded', () => checkForUpdates(false));
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

