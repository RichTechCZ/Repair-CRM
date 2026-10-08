<?php
/**
 * Web-based code updates are intentionally disabled.
 *
 * Production releases must be deployed as immutable artifacts outside the web
 * process. After activating a release, run `php run_migrations.php` with the
 * same environment configuration and fail the deployment if it returns nonzero.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'admin_access',
    'rate' => ['action' => 'run_update_disabled', 'max' => 5, 'window' => 300],
]);

api_json_exit([
    'success' => false,
    'message' => 'Web updates are disabled. Deploy a verified immutable release outside the CRM and then run php run_migrations.php.',
], 410);
