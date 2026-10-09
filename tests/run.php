<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../includes/telegram_webhook_security.php';
require_once __DIR__ . '/../includes/request_security.php';
require_once __DIR__ . '/../includes/upload_security.php';
require_once __DIR__ . '/../includes/sensitive_data.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/InvoiceAutomation.php';

function failTest(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        failTest($message);
    }
}

function assertSameValue($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        failTest($message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

assertTrue(isValidTelegramWebhookSecret('token_123', 'token_123'), 'matching webhook secrets must be accepted');
assertTrue(!isValidTelegramWebhookSecret('token_123', 'different'), 'different webhook secrets must be rejected');
assertTrue(!isValidTelegramWebhookSecret('', 'token_123'), 'an unset webhook secret must fail closed');
assertTrue(isValidTelegramWebhookSecretFormat('Abc-123_secret'), 'Telegram-compatible secret format must be accepted');
assertTrue(!isValidTelegramWebhookSecretFormat('contains space'), 'invalid webhook secret format must be rejected');
assertTrue(isValidTelegramWebhookUrl('https://crm.example.com/tg_webhook.php'), 'HTTPS webhook URL must be accepted');
assertTrue(!isValidTelegramWebhookUrl('http://crm.example.com/tg_webhook.php'), 'HTTP webhook URL must be rejected');
assertTrue(!isValidTelegramWebhookUrl('https://user:pass@crm.example.com/tg_webhook.php'), 'webhook URL credentials must be rejected');

assertTrue(requestUsesHttps(['HTTPS' => 'on']), 'direct HTTPS requests must set secure cookies');
assertTrue(!requestUsesHttps(['HTTP_X_FORWARDED_PROTO' => 'https']), 'forwarded HTTPS must not be trusted by default');
assertTrue(requestUsesHttps(['HTTP_X_FORWARDED_PROTO' => 'https'], true), 'trusted proxy HTTPS must set secure cookies');
assertTrue(!requestUsesHttps(['HTTP_X_FORWARDED_PROTO' => 'http'], true), 'trusted proxy HTTP must not set secure cookies');

assertSameValue(150.0, resolveInvoiceTotal(150, 100), 'final cost must be the invoice total');
assertSameValue(100.0, resolveInvoiceTotal(null, 100), 'estimated cost is used only when final cost is absent');
assertSameValue(0.0, resolveInvoiceTotal(0, 100), 'an explicit zero final cost must not fall back to estimate');

putenv('CRM_DATA_ENCRYPTION_KEY=' . base64_encode(str_repeat('k', 32)));
$encryptedPin = crmEncryptSensitiveValue('2580');
assertTrue(
    is_string($encryptedPin)
        && $encryptedPin !== '2580'
        && crmSensitiveDataIsEncrypted($encryptedPin),
    'device PINs must be stored as authenticated ciphertext'
);
assertSameValue('2580', crmDecryptSensitiveValue($encryptedPin), 'encrypted device PINs must round-trip');
$tamperedPin = substr($encryptedPin, 0, -2) . 'AA';
$tamperRejected = false;
try {
    crmDecryptSensitiveValue($tamperedPin);
} catch (RuntimeException $e) {
    $tamperRejected = true;
}
assertTrue($tamperRejected, 'tampered device PIN ciphertext must fail authentication');

$uploadPolicy = crmOrderUploadPolicy();
assertSameValue('jpg', $uploadPolicy['image/jpeg']['extension'] ?? null, 'JPEG uploads must use a server-selected extension');
assertTrue(!isset($uploadPolicy['application/x-httpd-php']), 'executable PHP MIME types must not be accepted');
assertSameValue(
    ['name' => 'photo.php', 'tmp_name' => 'tmp-file', 'error' => UPLOAD_ERR_OK, 'size' => 10],
    crmNormalizeUploadedFiles([
        'name' => ['photo.php'],
        'tmp_name' => ['tmp-file'],
        'error' => [UPLOAD_ERR_OK],
        'size' => [10],
    ])[0],
    'upload normalization must ignore the client-reported MIME type'
);

$telegramTestEndpoint = file_get_contents(__DIR__ . '/../api/test_tech_tg.php');
assertTrue(
    $telegramTestEndpoint !== false && strpos($telegramTestEndpoint, "'permission' => 'admin_access'") !== false,
    'the Telegram test endpoint must require admin_access'
);

$telegramWebhook = file_get_contents(__DIR__ . '/../tg_webhook.php');
assertTrue(
    $telegramWebhook !== false
        && strpos($telegramWebhook, 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN') !== false
        && strpos($telegramWebhook, 'isValidTelegramWebhookSecret') !== false
        && strpos($telegramWebhook, "loadEnv(__DIR__ . '/.env')") !== false,
    'the Telegram webhook must load its secret and validate Telegram headers before processing updates'
);

$telegramSetup = file_get_contents(__DIR__ . '/../set_tg.php');
assertTrue(
    $telegramSetup !== false
        && strpos($telegramSetup, 'validateCsrfToken') !== false
        && strpos($telegramSetup, "'secret_token' => \$webhookSecret") !== false,
    'Telegram webhook setup must be CSRF-protected and configure the secret token'
);

$automaticInvoice = file_get_contents(__DIR__ . '/../models/InvoiceAutomation.php');
assertTrue(
    $automaticInvoice !== false && strpos($automaticInvoice, 'SUM(quantity * price)') === false,
    'automatic invoice totals must not add order-item revenue'
);

$invoicePreview = file_get_contents(__DIR__ . '/../api/get_invoice_data.php');
assertTrue(
    $invoicePreview !== false && strpos($invoicePreview, 'SUM(quantity * price)') === false,
    'invoice preview totals must not add order-item revenue'
);

foreach (['print_order.php', 'print_thermal.php'] as $printDocument) {
    $printSource = file_get_contents(__DIR__ . '/../' . $printDocument);
    $usesOrderTotal =
        strpos($printSource, "\$order['final_cost'] ?? \$order['estimated_cost']") !== false
        || (
            strpos($printSource, "\$order['final_cost']") !== false
            && strpos($printSource, "\$order['estimated_cost']") !== false
            && strpos($printSource, 'order_total') !== false
        );
    assertTrue(
        $printSource !== false
            && strpos($printSource, "\$total += (\$item['price'] * \$item['quantity'])") === false
            && $usesOrderTotal,
        "{$printDocument} must print the order total without adding item prices again"
    );
}

$invoicePrintSource = file_get_contents(__DIR__ . '/../print_invoice.php');
assertTrue(
    $invoicePrintSource !== false
        && strpos($invoicePrintSource, '<strong>Vystavil:</strong>') !== false
        && strpos($invoicePrintSource, "echo \$h(\$issued_by)") === false
        && strpos($invoicePrintSource, "\$_SESSION['full_name']") === false
        && strpos($invoicePrintSource, "\$_SESSION['username']") === false,
    'A4 invoice print must keep Vystavil empty without a session display name'
);

$loginSource = file_get_contents(__DIR__ . '/../login.php');
assertTrue(
    $loginSource !== false
        && strpos($loginSource, "\$_SESSION['role']      = 'technician';") !== false
        && strpos($loginSource, "=== 'admin') ? 'admin'") === false,
    'technician records must never create system-admin sessions'
);
assertTrue(
    strpos($loginSource, 'username_hash') !== false
        && strpos($loginSource, 'loginRateLimitSchemaUnavailable') !== false
        && strpos($loginSource, 'allowing login until CLI migration') !== false
        && (strpos($loginSource, 'return false;') !== false)
        && (strpos($loginSource, 'blocking attempt') !== false || strpos($loginSource, 'fail closed') !== false || strpos($loginSource, 'Fail closed') !== false),
    'login throttling must allow an unprovisioned schema but fail closed on other store failures'
);

$editOrderSource = file_get_contents(__DIR__ . '/../edit_order.php');
assertTrue(
    $editOrderSource !== false
        && strpos($editOrderSource, 'crmStoreOrderUploads') !== false
        && strpos($editOrderSource, 'OrderStatusService::applyInTransaction') !== false
        && strpos($editOrderSource, "\$files['type']") === false,
    'full-page order editing must use centralized upload and status-transition services'
);

foreach ([
    'update_order_status.php',
    'update_order_full.php',
    'add_order_item.php',
    'update_order_item.php',
    'delete_order_item.php',
] as $lockedEndpoint) {
    $endpointSource = file_get_contents(__DIR__ . '/../api/' . $lockedEndpoint);
    assertTrue(
        $endpointSource !== false && strpos($endpointSource, 'FOR UPDATE') !== false,
        "{$lockedEndpoint} must lock mutable order/inventory state"
    );
}

$fullOrderUpdate = file_get_contents(__DIR__ . '/../api/update_order_full.php');
assertTrue(
    $fullOrderUpdate !== false
        && strpos($fullOrderUpdate, 'assertIssuedRequirements') !== false
        && strpos($fullOrderUpdate, 'shipping_method') !== false
        && strpos($fullOrderUpdate, "null,\n        false") === false,
    'full order updates must enforce shipping requirements before issuance'
);

require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../models/OrderStatusService.php';

assertTrue(
    OrderStatusService::isSelfPickupShippingMethod('Self Pickup')
        && OrderStatusService::isSelfPickupShippingMethod('self_pickup')
        && OrderStatusService::isSelfPickupShippingMethod('Клиент забрал сам')
        && OrderStatusService::isSelfPickupShippingMethod('Osobní odběr')
        && !OrderStatusService::isSelfPickupShippingMethod('PPL')
        && !OrderStatusService::isSelfPickupShippingMethod(''),
    'Self Pickup must be recognized as customer collection, not a carrier delivery'
);
assertTrue(
    OrderStatusService::isReclamationOrderType('Warranty')
        && OrderStatusService::isReclamationOrderType('Reclamation')
        && OrderStatusService::isReclamationOrderType('Рекламация')
        && OrderStatusService::isReclamationOrderType('Reklamace')
        && !OrderStatusService::isReclamationOrderType('Non-Warranty')
        && !OrderStatusService::isReclamationOrderType(''),
    'warranty/reclamation order types must be recognized'
);
assertTrue(
    !OrderStatusService::issuedRequiresFinalCost('Warranty', 'PPL')
        && !OrderStatusService::issuedRequiresFinalCost('Рекламация', 'Courier')
        && !OrderStatusService::issuedRequiresFinalCost('Non-Warranty', 'Self Pickup')
        && OrderStatusService::issuedRequiresFinalCost('Non-Warranty', 'PPL'),
    'reclamation orders must not require a final cost to issue'
);
assertTrue(
    !OrderStatusService::issuedRequiresDeliveryExpense('Self Pickup')
        && !OrderStatusService::issuedRequiresDeliveryExpense('')
        && OrderStatusService::issuedRequiresDeliveryExpense('PPL')
        && OrderStatusService::issuedRequiresDeliveryExpense('Zasilkovna'),
    'customer self-pickup must not require delivery expenses'
);
assertTrue(
    OrderStatusService::issuedShippingSatisfied('Self Pickup')
        && OrderStatusService::issuedShippingSatisfied('PPL')
        && !OrderStatusService::issuedShippingSatisfied('')
        && !OrderStatusService::issuedShippingSatisfied(null),
    'Self Pickup must satisfy the Issued shipping-method requirement'
);

$issuedSelfPickupOk = true;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 1500, 'Self Pickup', true);
} catch (Exception $e) {
    $issuedSelfPickupOk = false;
}
assertTrue($issuedSelfPickupOk, 'Issued + Self Pickup + final cost must not raise required_for_issue');

$issuedSelfPickupZeroOk = true;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 0, 'Self Pickup', true);
} catch (Exception $e) {
    $issuedSelfPickupZeroOk = false;
}
assertTrue(
    $issuedSelfPickupZeroOk,
    'Issued + Self Pickup must not require a final cost or show a notice'
);

$issuedLocalizedPickupOk = true;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 0, 'Клиент забрал сам', true);
} catch (Exception $e) {
    $issuedLocalizedPickupOk = false;
}
assertTrue($issuedLocalizedPickupOk, 'Issued + localized Self Pickup must not require a final cost');

$issuedMissingShipping = false;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 1500, '', true);
} catch (Exception $e) {
    $issuedMissingShipping = ($e->getMessage() === __('required_for_issue') || $e->getMessage() === 'required_for_issue');
}
assertTrue($issuedMissingShipping, 'Issued without a shipping method must still be rejected');

$issuedCarrierMissingCost = false;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 0, 'PPL', true);
} catch (Exception $e) {
    $issuedCarrierMissingCost = (
        $e->getMessage() === __('required_final_cost_for_issue')
        || $e->getMessage() === 'required_final_cost_for_issue'
    );
}
assertTrue(
    $issuedCarrierMissingCost,
    'Issued + carrier handover without a final cost must still be rejected'
);

$issuedReclamationZeroOk = true;
try {
    OrderStatusService::assertIssuedRequirements('Issued', 0, 'PPL', true, 'Warranty');
} catch (Exception $e) {
    $issuedReclamationZeroOk = false;
}
assertTrue(
    $issuedReclamationZeroOk,
    'Issued + reclamation/warranty order type must not require a final cost'
);

$viewOrderScripts = file_get_contents(__DIR__ . '/../includes/partials/view_order_scripts.php');
$finalCostAlertPos = strpos($viewOrderScripts, "showAlert('<?php echo __('required_final_cost_for_issue'); ?>')");
$selfPickupGuardPos = strpos($viewOrderScripts, 'isSelfPickup');
$helperPos = strpos($viewOrderScripts, 'function effectiveShippingMethod');
$readyPos = strpos($viewOrderScripts, '$(document).ready');
$confirmPos = strpos($viewOrderScripts, 'function showStatusConfirmModal');
assertTrue(
    $viewOrderScripts !== false
        && strpos($viewOrderScripts, 'finalCost <= 0 || !shippingMethod') === false
        && strpos($viewOrderScripts, 'showShippingRequiredModal') !== false
        && $selfPickupGuardPos !== false
        && strpos($viewOrderScripts, 'function isSelfPickupShipping') !== false
        && strpos($viewOrderScripts, 'function isReclamationOrderType') !== false
        && $helperPos !== false
        && $readyPos !== false
        && $confirmPos !== false
        && $helperPos < $readyPos
        && $helperPos < $confirmPos
        && ($finalCostAlertPos === false || $selfPickupGuardPos < $finalCostAlertPos),
    'order status form must not show a notice modal for Self Pickup'
);
assertTrue(
    $helperPos < $readyPos && $helperPos < $confirmPos,
    'status confirm modal must see effectiveShippingMethod in global scope so Issued confirm cannot hang'
);

assertSameValue(
    '2026-08-10 14:30:00',
    OrderStatusService::parseManualStatusDate('2026-08-10T14:30'),
    'status date editor must accept datetime-local values'
);
assertTrue(
    OrderStatusService::parseManualStatusDate('not-a-date') === null,
    'invalid status dates must be rejected'
);

$dateDb = new PDO('sqlite::memory:');
$dateDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dateDb->exec('CREATE TABLE order_status_log (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL,
    old_status TEXT,
    new_status TEXT,
    changed_by INTEGER,
    changed_role TEXT,
    changed_at TEXT
)');
$dateDb->exec("INSERT INTO order_status_log (id, order_id, old_status, new_status, changed_at) VALUES
    (1, 10969, 'Accepted', 'Ready', '2026-07-01 10:00:00'),
    (2, 10969, 'Ready', 'Issued', '2026-08-17 12:00:00')");
$syncResult = OrderStatusService::syncStatusHistoryDate($dateDb, 10969, 'Issued', '2026-08-10 14:30:00');
$updatedLog = $dateDb->query('SELECT id, changed_at FROM order_status_log WHERE order_id = 10969 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertTrue(
    $syncResult === 'updated'
        && $updatedLog[0]['changed_at'] === '2026-07-01 10:00:00'
        && $updatedLog[1]['changed_at'] === '2026-08-10 14:30:00',
    'editing the Status date must move only the current status-history row'
);

$dateEndpoint = file_get_contents(__DIR__ . '/../api/update_order_dates.php');
assertTrue(
    $dateEndpoint !== false
        && strpos($dateEndpoint, 'OrderStatusService::syncStatusHistoryDate') !== false
        && strpos($dateEndpoint, 'FOR UPDATE') !== false,
    'update_order_dates must rewrite the current status-history timestamp under lock'
);

$migrationRunner = file_get_contents(__DIR__ . '/../includes/migration_runner.php');
$updateEndpoint = file_get_contents(__DIR__ . '/../api/run_update.php');
assertTrue(
    $migrationRunner !== false
        && strpos($migrationRunner, 'crmApplyProductionHardeningMigration') !== false
        && strpos($migrationRunner, 'crmApplySearchSensitiveReportingMigration') !== false
        && strpos($migrationRunner, 'preg_replace(\'/\\D+/\', \'\',') !== false
        && strpos($migrationRunner, 'l.new_status = o.status') !== false
        && strpos($migrationRunner, 'crmMigrationResultsContainError') !== false,
    'schema hardening must be idempotent and migration execution must fail closed'
);
assertTrue(
    $updateEndpoint !== false
        && strpos($updateEndpoint, '410') !== false
        && strpos($updateEndpoint, 'applyArchiveUpdate') === false
        && strpos($updateEndpoint, 'runGitUpdate') === false,
    'the web process must not be able to overwrite live application files'
);

$accountingSource = file_get_contents(__DIR__ . '/../accounting.php');
assertTrue(
    $accountingSource !== false
        && strpos($accountingSource, '${data.name') === false
        && strpos($accountingSource, "querySelector('.item-name').value") !== false,
    'invoice item data must be assigned through DOM properties instead of HTML interpolation'
);

$configSource = file_get_contents(__DIR__ . '/../includes/config.php');
assertTrue(
    $configSource !== false
        && strpos($configSource, 'Privileged database accounts are forbidden in production') !== false
        && strpos($configSource, 'CREATE DATABASE') === false,
    'production database configuration must reject privileged users and never auto-create a database'
);

$headerSource = file_get_contents(__DIR__ . '/../includes/header.php');
$cspSource = file_get_contents(__DIR__ . '/../includes/content_security_policy.php');
assertTrue(
    $headerSource !== false
        && substr_count($headerSource, 'integrity="sha384-') >= 8
        && substr_count($headerSource, '<script nonce="<?php echo e(crmCspNonce()); ?>"') >= 7
        && strpos($headerSource, 'fonts.googleapis.com') === false,
    'third-party browser dependencies must be version-pinned and protected by SRI'
);
assertTrue(
    $cspSource !== false
        && strpos($cspSource, "header('Content-Security-Policy: '") !== false
        && strpos($cspSource, "script-src-attr 'none'") !== false
        && strpos($cspSource, "script-src 'self' 'nonce-") !== false,
    'HTML responses must enforce trusted-template nonce CSP and block inline event attributes'
);
assertTrue(
    strpos($cspSource, 'preg_replace_callback') === false
        && strpos($cspSource, "'unsafe-hashes'") === false,
    'CSP must never grant nonces or handler hashes to arbitrary rendered HTML'
);

$backupApiSource = file_get_contents(__DIR__ . '/../api/backup_db.php');
$downloadBackupSource = file_get_contents(__DIR__ . '/../api/download_backup.php');
$functionsSourceForBackup = file_get_contents(__DIR__ . '/../includes/functions.php');
assertTrue(
    $functionsSourceForBackup !== false
        && strpos($functionsSourceForBackup, 'function crmBackupDirectory') !== false
        && strpos($functionsSourceForBackup, "DIRECTORY_SEPARATOR . 'backup_db' . DIRECTORY_SEPARATOR") !== false
        && $backupApiSource !== false
        && strpos($backupApiSource, 'crmBackupDirectory(true)') !== false
        && strpos($backupApiSource, "dirname(__DIR__, 2)") === false
        && $downloadBackupSource !== false
        && strpos($downloadBackupSource, 'crmBackupDirectory(false)') !== false
        && strpos($downloadBackupSource, "dirname(__DIR__, 2)") === false,
    'SQL backups must default to app/backup_db via crmBackupDirectory()'
);
putenv('CRM_BACKUP_DIR');
$defaultBackupDir = str_replace('\\', '/', rtrim(crmBackupDirectory(false), '/\\'));
assertTrue(
    str_ends_with($defaultBackupDir, '/backup_db'),
    'default backup directory must be app/backup_db'
);

$applicationRoot = realpath(__DIR__ . '/..');
$phpFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($applicationRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($phpFiles as $phpFile) {
    if (!$phpFile->isFile() || strtolower($phpFile->getExtension()) !== 'php') {
        continue;
    }
    $relativePath = str_replace('\\', '/', substr($phpFile->getPathname(), strlen($applicationRoot) + 1));
    if (preg_match('#^(?:crm_backups|temp|backup_db|tests|\.agents|\.claude)/#', $relativePath)) {
        continue;
    }
    $phpSource = file_get_contents($phpFile->getPathname());
    assertTrue(
        $phpSource !== false && !preg_match('/\son(?:click|change|submit|load|error|input)\s*=/i', $phpSource),
        "{$relativePath} must not contain inline browser event attributes"
    );
    assertTrue(
        $phpSource !== false && !preg_match('/<script\b(?![^>]*\bnonce=)/i', $phpSource),
        "{$relativePath} script elements must carry the trusted response nonce explicitly"
    );
}

$reportsSource = file_get_contents(__DIR__ . '/../includes/reports_stats.php');
$reportOrdersSource = file_get_contents(__DIR__ . '/../api/get_orders_for_report.php');
$reportsPageSource = file_get_contents(__DIR__ . '/../reports.php');
assertTrue(
    $reportsSource !== false
        && strpos($reportsSource, 'getDetailedStatsBatch') !== false
        && strpos($reportsSource, 'status_dates.completed_at') !== false
        && strpos($reportsSource, '(SELECT inv.') === false
        && $reportOrdersSource !== false
        && strpos($reportOrdersSource, 'report_status.transition_at') !== false
        && strpos($reportOrdersSource, 'o.updated_at BETWEEN') === false,
    'reports must batch technician aggregation, use transition dates, and avoid correlated invoice queries'
);
assertTrue(
    $reportsSource !== false
        && strpos($reportsSource, 'max(0.0, $customerTotal - $partsCost - $extraExpenses) * ($rate / 100)') !== false
        && strpos($reportsSource, '$extraExpenses / 2') === false
        && $reportsPageSource !== false
        && strpos($reportsPageSource, '$e_cost / 2') === false
        && strpos($reportsPageSource, 'crmGetTechnicianPayroll($pdo, (int)$selected_tech_id, $start_date, $end_date)') !== false,
    'engineer payout must use full extra expenses (not half); the reports page renders lines from the shared payroll function'
);
assertTrue(
    $reportsSource !== false
        && strpos($reportsSource, 'function crmGetTechnicianPayroll') !== false
        && strpos($reportsPageSource, 'print_payroll_thermal.php') !== false
        && is_file(__DIR__ . '/../print_payroll_thermal.php'),
    'reports must expose 80mm payroll print for each technician'
);
$payrollPrintSource = file_get_contents(__DIR__ . '/../print_payroll_thermal.php');
assertTrue(
    $payrollPrintSource !== false
        && strpos($payrollPrintSource, 'crmGetTechnicianPayroll') !== false
        && strpos($payrollPrintSource, 'hasPermission(\'admin_access\')') !== false
        && strpos($payrollPrintSource, 'size: 80mm auto') === false // page size lives in shared thermal CSS
        && strpos($payrollPrintSource, 'crmThermalReceiptCss') !== false
        && strpos($payrollPrintSource, 'script-src') === false
        && preg_match('/<script\b(?![^>]*\bnonce=)/i', $payrollPrintSource) === 0,
    'payroll thermal print must reuse shared 80mm CSS, authorize access, and use CSP nonces'
);

$statisticsSource = file_get_contents(__DIR__ . '/../includes/statistics_dashboard.php');
$statisticsPageSource = file_get_contents(__DIR__ . '/../statistics.php');
assertTrue(
    $statisticsSource !== false
        && strpos($statisticsSource, 'function crmStatisticsPeriodOptions') !== false
        && strpos($statisticsSource, 'function crmGetDashboardStatistics') !== false
        && strpos($statisticsSource, 'isTechnicianScoped()') !== false
        && strpos($statisticsSource, 'currentTechnicianId()') !== false
        && strpos($statisticsSource, 'hasPermission(\'admin_access\')') !== false
        && strpos($statisticsSource, 'o.technician_id = ?') !== false
        && strpos($statisticsSource, "COUNT(*) AS count\\n") === false,
    'statistics must use a dedicated service with backend technician scoping'
);
assertTrue(
    $statisticsSource !== false
        && strpos($statisticsSource, "\$_GET['tech_id']") === false
        && strpos($statisticsSource, "\$_REQUEST['tech_id']") === false
        && strpos($statisticsSource, 'ALTER TABLE') === false
        && strpos($statisticsSource, 'CREATE TABLE') === false
        && strpos($statisticsSource, 'DROP TABLE') === false,
    'statistics must not accept a client technician id or mutate schema at runtime'
);
assertTrue(
    $statisticsPageSource !== false
        && strpos($statisticsPageSource, 'statistics_dashboard.php') !== false
        && strpos($statisticsPageSource, 'isTechnicianScoped()') !== false
        && strpos($statisticsPageSource, 'hasPermission(\'admin_access\')') !== false
        && strpos($statisticsPageSource, "\$_GET['tech_id']") === false,
    'statistics page must enforce access before rendering and expose no technician selector to scoped users'
);

if ($statisticsSource !== false) {
    require_once __DIR__ . '/../includes/statistics_dashboard.php';
    $periodOptions = crmStatisticsPeriodOptions(new DateTimeImmutable('2026-08-19'));
    assertTrue(
        array_keys($periodOptions) === ['week', 'month', 'six_months', 'year'],
        'statistics must expose the four required bounded periods'
    );
    assertSameValue('2026-08-17', $periodOptions['week']['start'], 'week period must start on the current week Monday');
    assertSameValue('2026-08-19', $periodOptions['week']['end'], 'week period must end on the current day');
    assertSameValue('2026-01-01', $periodOptions['year']['start'], 'year period must start on January 1');
    $customRange = crmStatisticsResolveRange(
        'custom',
        '2026-08-01',
        '2026-08-17',
        new DateTimeImmutable('2026-08-17')
    );
    assertSameValue('custom', $customRange['key'], 'statistics must accept a bounded custom range');
    $futureRange = crmStatisticsResolveRange(
        'custom',
        '2026-08-01',
        '2026-08-18',
        new DateTimeImmutable('2026-08-17')
    );
    assertSameValue('week', $futureRange['key'], 'statistics must reject future custom ranges');

    $sessionBeforeStatisticsScope = $_SESSION ?? [];
    $_SESSION['role'] = 'technician';
    $_SESSION['tech_id'] = 42;
    $_SESSION['_perms'] = [];
    $_SESSION['_perms_at'] = time();
    $scopedStatistics = crmStatisticsScope();
    assertSameValue(false, $scopedStatistics['is_admin'], 'technician statistics must not resolve to admin scope');
    assertSameValue(42, $scopedStatistics['technician_id'], 'technician statistics must use the session technician id');
    $scopeParams = [];
    assertSameValue('o.technician_id = ?', crmStatisticsScopeCondition(42, $scopeParams), 'statistics scope must use a bound technician predicate');
    assertSameValue([42], $scopeParams, 'statistics scope must bind the session technician id');
    $_SESSION = $sessionBeforeStatisticsScope;
}

$functionsSource = file_get_contents(__DIR__ . '/../includes/functions.php');
assertTrue(
    $functionsSource !== false
        && strpos($functionsSource, 'MATCH(') !== false
        && strpos($functionsSource, 'phone_search LIKE ?') !== false
        && strpos($functionsSource, "COALESCE({\$orderAlias}.pin_code") === false,
    'global order search must use indexed full-text/prefix paths, support serial fragments, and exclude encrypted PINs'
);
$serialSearch = buildOrderSearchQueryParts('EXP-1718', 'o', 'c', 't');
$serialContainsParams = array_values(array_filter(
    $serialSearch['where_params'],
    static function ($value): bool {
        return $value === '%EXP-1718%';
    }
));
assertSameValue(
    true,
    count($serialContainsParams) >= 2
        && $serialSearch['where_params'][0] === '%EXP-1718%'
        && $serialSearch['where_params'][1] === '%EXP-1718%',
    'S/N and IMEI2 searches must match identifier fragments anywhere in either serial field'
);
assertTrue(
    strpos(implode(' ', $serialSearch['where_clauses']), 'AGAINST') === false
        && strpos(implode(' ', $serialSearch['where_clauses']), 'phone_search') === false
        && strpos($serialSearch['score_sql'], 'AGAINST') === false
        && strpos($serialSearch['score_sql'], 'phone_search') === false,
    'identifier searches must not depend on optional full-text or phone-search schema'
);
$numericImeiSearch = buildOrderSearchQueryParts('358240051111110', 'o', 'c', 't');
assertSameValue(
    0,
    $numericImeiSearch['exact_id'],
    'a long bare numeric search must remain available for numeric IMEI/IMEI2 values'
);
assertTrue(
    in_array('%358240051111110%', $numericImeiSearch['where_params'], true),
    'a numeric IMEI/IMEI2 search must not be short-circuited as an order-ID lookup'
);
assertTrue(
    strpos($numericImeiSearch['score_sql'], 'serial_number_2 LIKE ?') !== false,
    'IMEI2 matches must receive relevance scoring'
);
$phoneSearch = buildOrderSearchQueryParts('+420 777 123 456', 'o', 'c', 't', false);
assertTrue(
    strpos(implode(' ', $phoneSearch['where_clauses']), 'c.email LIKE ?') !== false
        && strpos(implode(' ', $phoneSearch['where_clauses']), 'REPLACE(') !== false
        && in_array('%420777123456%', $phoneSearch['where_params'], true),
    'phone searches must use normalized digits and keep email in the global field set'
);
$nameSearch = buildOrderSearchQueryParts('John Smith', 'o', 'c', 't', false);
assertTrue(
    strpos(implode(' ', $nameSearch['where_clauses']), 'c.first_name LIKE ?') !== false
        && strpos(implode(' ', $nameSearch['where_clauses']), 'c.last_name LIKE ?') !== false
        && strpos(implode(' ', $nameSearch['where_clauses']), 'c.company LIKE ?') !== false
        && in_array('%John%', $nameSearch['where_params'], true)
        && in_array('%Smith%', $nameSearch['where_params'], true),
    'customer name and company searches must work token by token without full-text indexes'
);
$deviceSearch = buildOrderSearchQueryParts('Samsung Galaxy', 'o', 'c', 't', false);
assertTrue(
    strpos(implode(' ', $deviceSearch['where_clauses']), 'o.device_brand LIKE ?') !== false
        && strpos(implode(' ', $deviceSearch['where_clauses']), 'o.device_model LIKE ?') !== false
        && in_array('%Samsung%', $deviceSearch['where_params'], true)
        && in_array('%Galaxy%', $deviceSearch['where_params'], true),
    'device brand and model searches must work independently across the two order fields'
);
$orderIdSearch = buildOrderSearchQueryParts('#1718', 'o', 'c', 't');
assertSameValue(1718, $orderIdSearch['exact_id'], 'explicit # searches must target the exact order ID');
assertTrue(
    $orderIdSearch['where_clauses'] === ['o.id = ?']
        && $orderIdSearch['where_params'] === [1718],
    'explicit order-ID searches must remain exact and parameterized'
);
$dateSearch = buildOrderSearchQueryParts('03.08.2026', 'o', 'c', 't', false);
$dateRange = parseOrderSearchDateRange('03.08.2026');
assertTrue(
    $dateRange !== null
        && $dateRange['start'] === '2026-08-03 00:00:00'
        && $dateRange['end'] === '2026-08-04 00:00:00'
        && strpos(implode(' ', $dateSearch['where_clauses']), 'o.created_at >= ?') !== false
        && in_array('2026-08-03 00:00:00', $dateSearch['where_params'], true)
        && in_array('2026-08-04 00:00:00', $dateSearch['where_params'], true),
    'order date searches must use a precise created_at range'
);
$emailSearch = buildOrderSearchQueryParts('client@example.com', 'o', 'c', 't', false);
assertTrue(
    strpos(implode(' ', $emailSearch['where_clauses']), 'c.email LIKE ?') !== false
        && in_array('%client@example.com%', $emailSearch['where_params'], true),
    'email searches must match the customer email column'
);
$portableSearch = buildOrderSearchQueryParts('John', 'o', 'c', 't', false);
assertTrue(
    strpos(implode(' ', $portableSearch['where_clauses']), 'AGAINST') === false
        && strpos(implode(' ', $portableSearch['where_clauses']), 'phone_search') === false
        && strpos($portableSearch['score_sql'], 'AGAINST') === false
        && strpos($portableSearch['score_sql'], 'phone_search') === false,
    'portable search must not depend on optional indexes'
);
assertTrue(
    function_exists('searchOrdersList')
        && strpos($functionsSource, 'order search indexed fallback') !== false
        && strpos($functionsSource, 'buildOrderSearchQueryParts($search, \'o\', \'c\', \'t\', false)') !== false,
    'shared searchOrdersList must retry without optional indexes when the indexed path fails'
);
$indexSource = file_get_contents(__DIR__ . '/../index.php');
$ordersSource = file_get_contents(__DIR__ . '/../orders.php');
$headerSource = file_get_contents(__DIR__ . '/../includes/header.php');
assertTrue(
    $indexSource !== false
        && $ordersSource !== false
        && $headerSource !== false
        && strpos($indexSource, 'searchOrdersList(') !== false
        && strpos($ordersSource, 'searchOrdersList(') !== false
        && strpos($headerSource, 'order_search_placeholder') !== false
        && strpos($indexSource, 'buildOrderSearchQueryParts(') === false
        && strpos($ordersSource, 'buildOrderSearchQueryParts(') === false,
    'Dashboard and Orders must share searchOrdersList and the same order-search placeholder contract'
);
assertTrue(
    strpos($ordersSource, 'phone-qr-trigger') !== false
        && strpos($ordersSource, "title=\"<?php echo e(__('phone_show_qr')); ?>\"") === false
        && strpos($ordersSource, "title=\"<?php echo __('phone_show_qr'); ?>\"") === false,
    'phone number QR trigger must not show a hover tooltip'
);
assertTrue(
    strpos($functionsSource, 'currentUserCanCreateOrderForCustomer') !== false
        && strpos($functionsSource, '15 * 60') !== false,
    'technician onboarding must use a short-lived grant for newly created customers'
);

$addOrderSource = file_get_contents(__DIR__ . '/../api/add_order.php');
assertTrue(
    $addOrderSource !== false
        && strpos($addOrderSource, 'currentUserCanCreateOrderForCustomer') !== false
        && strpos($addOrderSource, 'crmEncryptSensitiveValue($pin_code)') !== false
        && strpos($addOrderSource, 'consumeCustomerOrderCreationGrant') !== false,
    'order creation must enforce customer scope and encrypt the device PIN'
);

$addCustomerSource = file_get_contents(__DIR__ . '/../api/add_customer.php');
$ordersScriptsSource = file_get_contents(__DIR__ . '/../includes/partials/orders_scripts.php');
assertTrue(
    $addCustomerSource !== false
        && strpos($addCustomerSource, 'api_json_exit') !== false
        && strpos($addCustomerSource, 'grantCustomerForOrderCreation') !== false
        && strpos($addCustomerSource, 'tableColumnExists') !== false
        && strpos($addCustomerSource, 'normalizePhoneForSearch') !== false
        && strpos($addCustomerSource, 'catch (Throwable') !== false
        && strpos($addCustomerSource, "response_format") !== false
        && $ordersScriptsSource !== false
        && strpos($ordersScriptsSource, "response_format: 'json'") !== false
        && strpos($ordersScriptsSource, "csrfFromMeta") !== false
        && strpos($indexSource, 'id="newOrderModal"') === false
        && strpos($indexSource, 'orders.php?new_order=1') !== false
        && strpos($headerSource, '!payload.csrf_token') !== false,
    'add_customer must emit clean JSON, support phone_search fallback, grant onboarding, and clients must send reliable CSRF/JSON markers'
);

$creditNoteSource = file_get_contents(__DIR__ . '/../models/InvoiceManager.php');
$exportSource = file_get_contents(__DIR__ . '/../export_utils.php');
assertTrue(
    $creditNoteSource !== false
        && strpos($creditNoteSource, '-abs((float)$original[\'total_amount\'])') !== false
        && strpos($creditNoteSource, '-abs((float)$item[\'price\'])') !== false
        && strpos($creditNoteSource, 'A credit note cannot be created from another credit note.') !== false
        && strpos($creditNoteSource, 'SELECT COUNT(*) FROM invoices') === false
        && strpos($creditNoteSource, '$highestSequence + 1') !== false
        && $exportSource !== false
        && strpos($exportSource, "['invoice_type']") !== false
        && strpos($exportSource, "'issuedCreditNotice'") !== false,
    'credit notes must persist signed negative values and export with the accounting credit-note document type'
);

$botRouterSource = file_get_contents(__DIR__ . '/../models/TelegramBotRouter.php');
$botHelperSource = file_get_contents(__DIR__ . '/../includes/telegram_bot.php');
$botMigrationSource = file_get_contents(__DIR__ . '/../migrations/006_telegram_bot_state.sql');
assertTrue(
    $botRouterSource !== false
        && strpos($botRouterSource, 'OrderStatusService::applyInTransaction') !== false
        && strpos($botRouterSource, 'telegramCanAccessOrder') !== false
        && strpos($botRouterSource, 'getDetailedStatsBatch') !== false
        && strpos($botRouterSource, 'telegramSaveMediaAttachment') !== false,
    'TelegramBotRouter must use centralized OrderStatusService, access scoping, and media upload helpers'
);
assertTrue(
    $botHelperSource !== false
        && strpos($botHelperSource, 'crmOrderUploadPolicy') !== false
        && strpos($botHelperSource, 'crmEnsureUploadDirectory') !== false
        && strpos($botHelperSource, 'random_bytes(24)') !== false
        && strpos($botHelperSource, 'order_attachments') !== false,
    'telegram_bot helpers must enforce upload security policies, random naming, and secure MIME handling'
);
assertTrue(
    $botMigrationSource !== false
        && strpos($botMigrationSource, 'CREATE TABLE IF NOT EXISTS `telegram_bot_states`') !== false,
    '006_telegram_bot_state migration must provision telegram_bot_states'
);

// Scoping unit check with in-memory SQLite DB
$testDb = new PDO('sqlite::memory:');
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, technician_id INTEGER)");
$testDb->exec("INSERT INTO orders (id, technician_id) VALUES (101, 2), (102, 5)");

require_once __DIR__ . '/../includes/telegram_bot.php';

$techUser = ['type' => 'technician', 'id' => 2, 'technician_id' => 2, 'is_admin' => false];
$otherTechUser = ['type' => 'technician', 'id' => 3, 'technician_id' => 3, 'is_admin' => false];
$adminUser = ['type' => 'admin', 'id' => 1, 'technician_id' => null, 'is_admin' => true];

assertTrue(
    telegramCanAccessOrder($testDb, $techUser, 101),
    'technician must have access to their own order in Telegram bot'
);
assertTrue(
    !telegramCanAccessOrder($testDb, $otherTechUser, 101),
    'technician must be forbidden from accessing other technicians orders in Telegram bot'
);
assertTrue(
    telegramCanAccessOrder($testDb, $adminUser, 101) && telegramCanAccessOrder($testDb, $adminUser, 102),
    'administrator must have access to all orders in Telegram bot'
);

$testDb->exec("CREATE TABLE telegram_bot_states (telegram_id VARCHAR(64) PRIMARY KEY, state VARCHAR(64) NOT NULL, order_id INTEGER NULL, temp_data TEXT NULL, updated_at DATETIME NOT NULL)");

telegramSetState($testDb, '2427615', 'WAITING_NOTE_APPEND', 101, ['foo' => 'bar']);
$savedState = telegramGetState($testDb, '2427615');
assertTrue(
    $savedState !== null
        && $savedState['state'] === 'WAITING_NOTE_APPEND'
        && (int)$savedState['order_id'] === 101
        && ($savedState['temp_data']['foo'] ?? '') === 'bar',
    'telegramSetState and telegramGetState must store and retrieve dialog state by Telegram user ID'
);

telegramClearState($testDb, '2427615');
$clearedState = telegramGetState($testDb, '2427615');
assertTrue(
    $clearedState === null,
    'telegramClearState must remove the state record'
);

// ── Regression: fixes from the 2026-10 audit ────────────────────────────────
$autoInvoiceSource = (string)file_get_contents(__DIR__ . '/../models/InvoiceAutomation.php');
assertTrue(
    strpos($autoInvoiceSource, "WHERE order_id = ? AND status <> 'cancelled'") !== false,
    'auto-invoice creation must not reuse a cancelled invoice'
);
$reportSource = (string)file_get_contents(__DIR__ . '/../includes/reports_stats.php');
assertTrue(
    substr_count($reportSource, "AND status <> 'cancelled'") === 2,
    'both revenue queries must ignore cancelled invoices'
);
$managerSource = (string)file_get_contents(__DIR__ . '/../models/InvoiceManager.php');
assertTrue(
    strpos($managerSource, 'COALESCE(payment_date, ?)') !== false
        && strpos($managerSource, '$existingPaymentDate ?: date') !== false,
    'an already recorded payment date must be preserved'
);
$actionsSource = (string)file_get_contents(__DIR__ . '/../accounting_actions.php');
assertTrue(
    strpos($actionsSource, "['draft', 'cancelled']") !== false,
    'issued or paid invoices must not be deletable'
);
$settingsSource = (string)file_get_contents(__DIR__ . '/../settings.php');
assertTrue(
    strpos($settingsSource, "'secret_token' => \$webhook_secret") !== false
        && strpos($settingsSource, 'drop_pending_updates') === false,
    'saving integrations must re-register the webhook with its secret token'
);
$configSource = (string)file_get_contents(__DIR__ . '/../includes/config.php');
assertTrue(
    strpos($configSource, 'SELECT is_active FROM technicians') !== false,
    'deactivated technicians must lose their live session'
);
$_SESSION['role'] = 'technician';
$_SESSION['tech_id'] = 42;
$_SESSION['_perms'] = ['admin_access'];
$_SESSION['_perms_at'] = time();
assertTrue(hasPermission('admin_access'), 'fresh cached permissions must be honoured');
unset($_SESSION['_perms'], $_SESSION['_perms_at'], $_SESSION['role'], $_SESSION['tech_id']);

$footerSource = (string)file_get_contents(__DIR__ . '/../includes/footer.php');
$headerSource = (string)file_get_contents(__DIR__ . '/../includes/header.php');
assertTrue(
    strpos($footerSource, 'class="ios-tabbar d-lg-none"') !== false
        && strpos($headerSource, 'assets/css/mobile.css') !== false
        && strpos($headerSource, 'viewport-fit=cover') !== false,
    'phone layout must ship the tab bar, mobile stylesheet and safe-area viewport'
);
$mobileCss = (string)file_get_contents(__DIR__ . '/../assets/css/mobile.css');
assertTrue(
    strpos($mobileCss, 'env(safe-area-inset-bottom') !== false && strpos($mobileCss, 'font-size: 16px') !== false,
    'phone stylesheet must respect safe areas and keep 16px inputs (no iOS zoom)'
);
assertTrue(
    strpos((string)file_get_contents(__DIR__ . '/../assets/js/mobile.js'), 'nonce') === false,
    'mobile.js must stay a plain external script (CSP nonce is applied by the template)'
);

$styleCss = (string)file_get_contents(__DIR__ . '/../assets/css/style.css');
assertTrue(strpos($styleCss, 'gradient') === false, 'UI surfaces and buttons must stay flat (no gradients)');
assertTrue(strpos($styleCss, '-glow') === false, 'glow badge helpers must not return');
assertTrue(
    strpos($headerSource, 'Service operations') === false && strpos($headerSource, '>Go<') === false,
    'shell must not ship stock English filler labels'
);

// ── Regression: 2026-10-08 production audit ─────────────────────────────────
$sessionBeforeAudit = $_SESSION ?? [];
$pdoBeforeAudit = $GLOBALS['pdo'] ?? null;

$auditDb = new PDO('sqlite::memory:');
$auditDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auditDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$auditDb->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT, shipping_date TEXT)');
$auditDb->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, inventory_id INTEGER, quantity INTEGER)');
$auditDb->exec('CREATE TABLE order_status_log (id INTEGER PRIMARY KEY, order_id INTEGER, old_status TEXT, new_status TEXT,
    changed_by INTEGER, changed_role TEXT, changed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$auditDb->exec("INSERT INTO orders (id, status, shipping_date) VALUES (1, 'In Repair', NULL), (2, 'In Repair', '2026-09-01 10:00:00')");
$GLOBALS['pdo'] = $auditDb;
$_SESSION = ['user_id' => 't7', 'role' => 'technician', 'tech_id' => 7, '_perms' => [], '_perms_at' => time()];

$auditDb->beginTransaction();
OrderStatusService::applyInTransaction($auditDb, 1, 'In Repair', 'Issued', 100);
OrderStatusService::applyInTransaction($auditDb, 2, 'In Repair', 'Issued', 100);
$auditDb->commit();
assertTrue(
    $auditDb->query('SELECT shipping_date FROM orders WHERE id = 1')->fetchColumn() !== null,
    'every transition into Issued must stamp shipping_date so the order enters finance periods'
);
assertSameValue(
    '2026-09-01 10:00:00',
    $auditDb->query('SELECT shipping_date FROM orders WHERE id = 2')->fetchColumn(),
    'an operator-set shipping_date must be preserved when entering Issued'
);
$techLog = $auditDb->query('SELECT changed_by, changed_role FROM order_status_log WHERE order_id = 1')->fetch();
assertTrue(
    $techLog !== false && (int)$techLog['changed_by'] === 7 && $techLog['changed_role'] === 'technician',
    'technician status changes must log the numeric technicians.id, not the session string "t<id>"'
);

$closedEditBlocked = false;
try {
    OrderStatusService::assertClosedOrderEditable('Issued', false);
} catch (Exception $e) {
    $closedEditBlocked = true;
}
assertTrue($closedEditBlocked, 'technicians must not change money, parts or dates of a closed order');
OrderStatusService::assertClosedOrderEditable('Issued', true);
OrderStatusService::assertClosedOrderEditable('In Repair', false);

$_SESSION = $sessionBeforeAudit;
$GLOBALS['pdo'] = $pdoBeforeAudit;

foreach (['update_order_item', 'delete_order_item', 'add_order_item', 'update_order_dates', 'update_shipping'] as $closedEndpoint) {
    assertTrue(
        strpos((string)file_get_contents(__DIR__ . '/../api/' . $closedEndpoint . '.php'), 'assertClosedOrderEditable') !== false,
        "api/{$closedEndpoint}.php must enforce the closed-order edit lock"
    );
}

// Exports: generated in memory, XML-escaped, CSV formula-safe.
require_once __DIR__ . '/../export_utils.php';
$exportDb = new PDO('sqlite::memory:');
$exportDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$exportDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$exportDb->exec('CREATE TABLE system_settings (setting_key TEXT, setting_value TEXT)');
$exportDb->exec("INSERT INTO system_settings VALUES ('acc_ico', '123&456')");
$GLOBALS['pdo'] = $exportDb;
$exportDb->exec('CREATE TABLE invoices (id INTEGER, customer_id INTEGER, invoice_number TEXT, invoice_type TEXT, date_issue TEXT,
    date_tax TEXT, date_due TEXT, payment_method TEXT, is_vat_payer INTEGER)');
$exportDb->exec('CREATE TABLE customers (id INTEGER, company TEXT, first_name TEXT, last_name TEXT, address TEXT, ico TEXT, dic TEXT)');
$exportDb->exec('CREATE TABLE invoice_items (id INTEGER, invoice_id INTEGER, item_name TEXT, quantity REAL, unit TEXT, vat_rate REAL, price REAL)');
$exportDb->exec("INSERT INTO invoices VALUES (1, 1, '2026/001', 'invoice', '2026-10-01', '2026-10-01', '2026-10-15', 'cash', 1)");
$exportDb->exec("INSERT INTO customers VALUES (1, 'Smith & Sons <Ltd>', '', '', 'Street 1, Praha', '', '')");
$exportDb->exec("INSERT INTO invoice_items VALUES (1, 1, '=cmd|calc', 1, 'ks', 21, 100)");
$exporter = new AccountingExporter($exportDb);
$pohoda = $exporter->exportToPohoda(1);
$pohodaDom = new DOMDocument();
assertTrue(
    @$pohodaDom->loadXML($pohoda['content']) && strpos($pohoda['content'], 'Smith &amp; Sons &lt;Ltd&gt;') !== false,
    'Pohoda export must stay well-formed XML when customer data contains & or <'
);
$s3 = $exporter->exportToS3Money(1);
$GLOBALS['pdo'] = $pdoBeforeAudit;
assertTrue(
    strpos($s3['content'], "'=cmd|calc") !== false && $s3['filename'] === basename($s3['filename']),
    'S3 Money CSV must neutralise spreadsheet formulas and use a safe file name'
);

$headerSource = (string)file_get_contents(__DIR__ . '/../includes/header.php');
assertTrue(
    strpos($headerSource, "\$_SERVER['PHP_SELF']") === false && strpos($headerSource, "basename(\$_SERVER['SCRIPT_NAME'])") !== false,
    'page permission map must use SCRIPT_NAME: PHP_SELF includes PATH_INFO and lets /page.php/x skip the check'
);
foreach (['edit_inventory.php' => 'admin_access', 'inventory.php' => 'admin_access', 'customers.php' => 'edit_customers', 'edit_customer.php' => 'edit_customers'] as $guardedPage => $guardPermission) {
    $guardedSource = (string)file_get_contents(__DIR__ . '/../' . $guardedPage);
    $guardPos = strpos($guardedSource, "hasPermission('{$guardPermission}')");
    $headerPos = strpos($guardedSource, "require_once 'includes/header.php'");
    assertTrue($guardPos !== false && $headerPos !== false && $guardPos < $headerPos, "{$guardedPage} must check {$guardPermission} itself");
}
foreach (['includes/partials/orders_scripts.php'] as $select2Page) {
    assertTrue(
        strpos((string)file_get_contents(__DIR__ . '/../' . $select2Page), "return \$('<span>').text(item.text || item.name || '');") !== false,
        "{$select2Page} must render the selected customer as text (escapeMarkup is a no-op there)"
    );
}
$telegramHelperSource = (string)file_get_contents(__DIR__ . '/../includes/telegram_bot.php');
assertTrue(
    stripos($telegramHelperSource, 'CREATE TABLE') === false && strpos($telegramHelperSource, '$usernameWithAt') === false,
    'Telegram runtime must not run DDL and must bind users by numeric Telegram id only'
);
$botSource = (string)file_get_contents(__DIR__ . '/../models/TelegramBotRouter.php');
assertTrue(
    strpos($botSource, "searchOrdersList(\$pdo, \$query, \$techId, null, 6, 0, false)") !== false
        && strpos($botSource, "\$batch['total_revenue']") === false
        && strpos($botSource, "\$stats['engineer_payout']") === false,
    'Telegram search must request 6 rows from offset 0 and reports must read real getDetailedStatsBatch keys'
);
$runnerSource = (string)file_get_contents(__DIR__ . '/../includes/migration_runner.php');
assertTrue(
    strpos($runnerSource, "'007_query_indexes.sql'") !== false && strpos($runnerSource, '\\d{3}_[A-Za-z0-9_]+\\.sql') !== false,
    'migration runner must own 007 and execute only numbered migration files'
);
$logoutSource = (string)file_get_contents(__DIR__ . '/../logout.php');
assertTrue(
    strpos($logoutSource, 'validateCsrfToken') !== false && strpos($logoutSource, "includes/config.php") !== false,
    'logout must be POST + CSRF and use the shared session configuration'
);

$fullUpdateSource = (string)file_get_contents(__DIR__ . '/../api/update_order_full.php');
assertTrue(
    strpos($fullUpdateSource, "(\$postedFinal === null && \$current['final_cost'] === null) ? null : \$incoming_final_cost") !== false
        && strpos($fullUpdateSource, '$currentRevenueBase') !== false,
    'full order edit must keep a NULL final cost NULL and compare closed-order money against the revenue base in use'
);
$shippingSource = (string)file_get_contents(__DIR__ . '/../api/update_shipping.php');
assertTrue(
    strpos($shippingSource, 'if ($methodChanged || $dateChanged)') !== false,
    'technicians must still add a tracking number to an issued order; only method/date changes are admin-only'
);
$editOrderPageSource = (string)file_get_contents(__DIR__ . '/../edit_order.php');
assertTrue(
    strpos($editOrderPageSource, 'assertClosedOrderEditable($canonical_current, $is_admin)') !== false,
    'the full-page order editor must apply the same closed-order money lock as api/update_order_full.php'
);
foreach (['api/add_order.php', 'models/OrderStatusService.php', 'includes/functions.php', 'print_workshop.php', 'print_reception_thermal.php'] as $linkSourceFile) {
    assertTrue(
        strpos((string)file_get_contents(__DIR__ . '/../' . $linkSourceFile), "\$_SERVER['HTTP_HOST']") === false,
        "{$linkSourceFile} must build absolute links from crmPublicBaseUrl(), never the client Host header"
    );
}
$expressInvoiceSource = (string)file_get_contents(__DIR__ . '/../api/create_express_invoice.php');
assertTrue(
    strpos($expressInvoiceSource, "invoice_type <> 'credit_note'") !== false,
    'the express invoice form must never edit a credit note'
);

// Legacy orders without a brand must still be editable (this used to throw a TypeError and roll back the save).
saveDeviceModelUsage(null, null);
assertTrue(true, 'saveDeviceModelUsage accepts NULL brand/model');

$rateLimitSource = (string)file_get_contents(__DIR__ . '/../includes/rate_limit.php');
assertTrue(
    strpos($rateLimitSource, 'Rate limiter purge failed') !== false,
    'a failed rate-limit purge must not reject an already allowed request'
);

echo "OK: security and financial regression checks passed\n";

