<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Check admin access
if (!hasPermission('admin_access')) {
    echo '<div class="container-fluid"><div class="alert alert-danger order-created-feedback" role="alert">' . e(__('access_denied')) . '</div></div>';
    require_once 'includes/footer.php';
    exit;
}

// Handle Settings Update
$settings_saved = false;
$settings_error = '';
if (isset($_POST['save_acc_settings'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $settings_error = 'Security token invalid.';
    } else {
        $myinvoiceBaseUrl = trim((string)($_POST['myinvoice_api_base_url'] ?? 'https://fakturace.43.157.31.121.sslip.io'));
        $myinvoiceScheme = strtolower((string)parse_url($myinvoiceBaseUrl, PHP_URL_SCHEME));
        $myinvoiceHost = strtolower((string)parse_url($myinvoiceBaseUrl, PHP_URL_HOST));
        if (
            $myinvoiceScheme !== 'https' &&
            !in_array($myinvoiceHost, ['localhost', '127.0.0.1', '::1'], true)
        ) {
            $settings_error = 'MyInvoice API requires HTTPS.';
        } else {
            set_setting('acc_company_name', $_POST['acc_company_name']);
            set_setting('acc_address', $_POST['acc_address']);
            set_setting('acc_ico', $_POST['acc_ico']);
            set_setting('acc_dic', $_POST['acc_dic']);
            set_setting('acc_bank_name', $_POST['acc_bank_name']);
            set_setting('acc_bank_account', $_POST['acc_bank_account']);
            set_setting('acc_iban', $_POST['acc_iban']);
            set_setting('acc_swift', $_POST['acc_swift']);
            set_setting('acc_trade_register', $_POST['acc_trade_register'] ?? '');
            set_setting('acc_invoice_prefix', $_POST['acc_invoice_prefix']);
            set_setting('acc_auto_create_invoice', isset($_POST['acc_auto_create_invoice']) ? 1 : 0);
            set_setting('acc_is_vat_payer', isset($_POST['acc_is_vat_payer']) ? 1 : 0);
            set_setting('acc_vat_rate', $_POST['acc_vat_rate']);
            set_setting('myinvoice_enabled', isset($_POST['myinvoice_enabled']) ? 1 : 0);
            set_setting('myinvoice_auto_issue', isset($_POST['myinvoice_auto_issue']) ? 1 : 0);
            set_setting('myinvoice_api_base_url', $myinvoiceBaseUrl);
            set_setting('myinvoice_default_country_id', $_POST['myinvoice_default_country_id'] ?? '1');
            set_setting('myinvoice_default_street', $_POST['myinvoice_default_street'] ?? '-');
            set_setting('myinvoice_default_city', $_POST['myinvoice_default_city'] ?? 'Praha');
            set_setting('myinvoice_default_zip', $_POST['myinvoice_default_zip'] ?? '11000');
            set_setting('myinvoice_fallback_email', $_POST['myinvoice_fallback_email'] ?? '');
            $settings_saved = true;
        }
    }
}

// Fetch Invoices with items
$stmt = $pdo->query("SELECT i.*, c.first_name, c.last_name, c.company,
    (SELECT GROUP_CONCAT(item_name SEPARATOR ', ') FROM invoice_items WHERE invoice_id = i.id) as item_names
    FROM invoices i JOIN customers c ON i.customer_id = c.id ORDER BY i.created_at DESC");
$invoices = $stmt->fetchAll();

// Fetch Customers for select
$stmt = $pdo->query("SELECT id, first_name, last_name, company FROM customers ORDER BY company, last_name");
$customers = $stmt->fetchAll();

// Summary metrics for overview strip
$invoice_stats = $pdo->query(
    "SELECT status, COUNT(*) AS count, COALESCE(SUM(total_amount), 0) AS total
     FROM invoices GROUP BY status"
)->fetchAll(PDO::FETCH_ASSOC);
$stats_by_status = [];
$invoice_total_count = 0;
$invoice_total_amount = 0.0;
foreach ($invoice_stats as $row) {
    $stats_by_status[$row['status']] = $row;
    $invoice_total_count += (int)$row['count'];
    $invoice_total_amount += (float)$row['total'];
}
$metric_order = ['paid', 'issued', 'overdue', 'draft', 'cancelled'];
$active_tab = ($_GET['tab'] ?? 'list') === 'stats' ? 'stats' : 'list';
?>

<div class="container-fluid accounting-page">
    <div class="page-header">
        <div class="page-header__copy">
            <div class="page-kicker"><?php echo e(get_setting('company_name', 'Repair CRM')); ?></div>
            <h1><?php echo __('accounting'); ?></h1>
            <p class="page-subtitle">
                <?php echo e(__('invoices_list')); ?>:
                <strong class="financial-number"><?php echo (int)$invoice_total_count; ?></strong>
                ·
                <span class="financial-number"><?php echo number_format($invoice_total_amount, 2, '.', ' '); ?> Kč</span>
            </p>
        </div>
        <div class="page-actions">
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#accSettingsModal" title="<?php echo e(__('acc_settings')); ?>">
                <i class="fas fa-cog" aria-hidden="true"></i>
                <span><?php echo __('acc_settings'); ?></span>
            </button>
            <button type="button" class="btn btn-primary" data-crm-action="show-new-invoice">
                <i class="fas fa-plus" aria-hidden="true"></i>
                <span><?php echo __('new_invoice'); ?></span>
            </button>
        </div>
    </div>

    <?php if ($settings_saved): ?>
        <div class="alert alert-success order-created-feedback" role="status"><?php echo e(__('settings_saved')); ?></div>
    <?php elseif ($settings_error !== ''): ?>
        <div class="alert alert-danger order-created-feedback" role="alert"><?php echo e($settings_error); ?></div>
    <?php endif; ?>

    <section class="workspace-overview workspace-overview--accounting ui-ready" aria-label="<?php echo e(__('invoice_summary')); ?>">
        <div class="workspace-overview__metrics workspace-overview__metrics--five">
            <?php
            $overview_statuses = [
                'paid' => __('status_paid'),
                'issued' => __('status_invoice_issued'),
                'overdue' => __('status_overdue'),
                'draft' => __('status_draft'),
                'cancelled' => __('status_cancelled'),
            ];
            foreach ($overview_statuses as $status_key => $status_label):
                $row = $stats_by_status[$status_key] ?? ['count' => 0, 'total' => 0];
            ?>
            <div class="workspace-overview__metric">
                <span class="workspace-overview__metric-label"><?php echo e($status_label); ?></span>
                <span class="workspace-overview__metric-data">
                    <strong class="workspace-overview__metric-value financial-number"><?php echo (int)$row['count']; ?></strong>
                    <?php echo getInvoiceStatusBadge($status_key); ?>
                </span>
                <span class="workspace-overview__metric-note financial-number">
                    <?php echo number_format((float)$row['total'], 2, '.', ' '); ?> Kč
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <ul class="nav nav-pills mb-4 glass-panel p-2 ui-ready" id="accTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'list' ? 'active' : ''; ?>" id="list-tab" data-bs-toggle="tab" data-bs-target="#list-pane" type="button" role="tab" aria-controls="list-pane" aria-selected="<?php echo $active_tab === 'list' ? 'true' : 'false'; ?>">
                <i class="fas fa-file-invoice me-2" aria-hidden="true"></i><?php echo __('invoices_list'); ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $active_tab === 'stats' ? 'active' : ''; ?>" id="stats-tab" data-bs-toggle="tab" data-bs-target="#stats-pane" type="button" role="tab" aria-controls="stats-pane" aria-selected="<?php echo $active_tab === 'stats' ? 'true' : 'false'; ?>">
                <i class="fas fa-chart-pie me-2" aria-hidden="true"></i><?php echo __('invoice_summary'); ?>
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade <?php echo $active_tab === 'list' ? 'show active' : ''; ?>" id="list-pane" role="tabpanel" aria-labelledby="list-tab">
            <div class="card glass-card ui-ready">
                <div class="card-body p-0">
                    <div class="table-responsive table-scroll-touch accounting-table-wrap">
                        <table class="table table-hover align-middle mb-0 accounting-table table-mobile-cards">
                            <thead>
                                <tr>
                                    <th class="ps-4"><?php echo __('invoice_number'); ?></th>
                                    <th><?php echo __('date_issue'); ?></th>
                                    <th><?php echo __('customer'); ?></th>
                                    <th><?php echo __('item_name'); ?></th>
                                    <th><?php echo __('status'); ?></th>
                                    <th class="text-end"><?php echo __('amount'); ?></th>
                                    <th class="text-end pe-4"><?php echo __('action'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($invoices)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-5 text-white-75">
                                            <i class="fas fa-file-invoice fa-3x mb-3 d-block opacity-25" aria-hidden="true"></i>
                                            <?php echo e(__('no_invoices')); ?>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($invoices as $inv): ?>
                                    <?php
                                        $inv_id = (int)$inv['id'];
                                        $inv_number = (string)$inv['invoice_number'];
                                        $inv_customer = $inv['company'] ?: trim(($inv['first_name'] ?? '') . ' ' . ($inv['last_name'] ?? ''));
                                        $preview_title = e(__('invoice') . ' ' . $inv_number);
                                    ?>
                                    <tr>
                                        <td class="ps-4" data-label="<?php echo e(__('invoice_number')); ?>">
                                            <a href="#"
                                               class="accounting-invoice-link"
                                               data-crm-action="open-preview"
                                               data-preview-url="print_invoice.php?id=<?php echo $inv_id; ?>"
                                               data-preview-title="<?php echo $preview_title; ?>">
                                                <?php echo htmlspecialchars($inv_number); ?>
                                            </a>
                                            <?php if (!empty($inv['order_id'])): ?>
                                                <div class="small text-white-75">
                                                    <i class="fas fa-link me-1" aria-hidden="true"></i>
                                                    <a href="view_order.php?id=<?php echo (int)$inv['order_id']; ?>" class="text-white-75">
                                                        #<?php echo (int)$inv['order_id']; ?>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-nowrap" data-label="<?php echo e(__('date_issue')); ?>"><?php echo date('d.m.Y', strtotime($inv['date_issue'])); ?></td>
                                        <td data-label="<?php echo e(__('customer')); ?>"><?php echo htmlspecialchars($inv_customer); ?></td>
                                        <td class="accounting-items-cell" data-label="<?php echo e(__('item_name')); ?>">
                                            <span class="text-white-75" title="<?php echo e($inv['item_names'] ?: ''); ?>">
                                                <?php echo htmlspecialchars($inv['item_names'] ?: '—'); ?>
                                            </span>
                                        </td>
                                        <td data-label="<?php echo e(__('status')); ?>"><?php echo getInvoiceStatusBadge($inv['status']); ?></td>
                                        <td class="text-end text-nowrap" data-label="<?php echo e(__('amount')); ?>">
                                            <strong class="financial-number">
                                                <?php echo number_format((float)$inv['total_amount'], 2, '.', ' '); ?>
                                            </strong>
                                            <span class="text-white-75 small"><?php echo htmlspecialchars($inv['currency'] ?? 'Kč'); ?></span>
                                        </td>
                                        <td class="text-end pe-4 mobile-row-actions" data-label="">
                                            <div class="btn-group btn-group-sm accounting-row-actions" role="group" aria-label="<?php echo e(__('action')); ?>">
                                                <button type="button"
                                                        class="btn btn-outline-secondary"
                                                        data-crm-action="open-preview"
                                                        data-preview-url="print_invoice.php?id=<?php echo $inv_id; ?>"
                                                        data-preview-title="<?php echo $preview_title; ?>"
                                                        title="<?php echo e(__('preview_btn')); ?>"
                                                        aria-label="<?php echo e(__('preview_btn')); ?>">
                                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                                </button>
                                                <button type="button"
                                                        class="btn btn-outline-secondary"
                                                        data-crm-action="edit-invoice"
                                                        data-crm-id="<?php echo $inv_id; ?>"
                                                        title="<?php echo e(__('edit')); ?>"
                                                        aria-label="<?php echo e(__('edit')); ?>">
                                                    <i class="fas fa-edit" aria-hidden="true"></i>
                                                </button>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button"
                                                            class="btn btn-outline-secondary dropdown-toggle"
                                                            data-bs-toggle="dropdown"
                                                            data-bs-popper-config='{"strategy":"fixed"}'
                                                            aria-expanded="false"
                                                            title="<?php echo e(__('more_actions')); ?>"
                                                            aria-label="<?php echo e(__('more_actions')); ?>">
                                                        <i class="fas fa-ellipsis-h" aria-hidden="true"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-crm-action="open-preview" data-preview-url="print_invoice.php?id=<?php echo $inv_id; ?>" data-preview-title="<?php echo $preview_title; ?>">
                                                                <i class="fas fa-print me-2 text-white-75" aria-hidden="true"></i><?php echo __('print_invoice_btn'); ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-crm-action="open-preview" data-preview-url="print_invoice_thermal.php?id=<?php echo $inv_id; ?>" data-preview-title="<?php echo e(__('thermal_receipt') . ' ' . $inv_number); ?>">
                                                                <i class="fas fa-receipt me-2 text-white-75" aria-hidden="true"></i><?php echo __('thermal_receipt'); ?>
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-crm-action="create-credit-note" data-crm-id="<?php echo $inv_id; ?>">
                                                                <i class="fas fa-undo me-2 text-white-75" aria-hidden="true"></i><?php echo __('create_credit_note'); ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-crm-action="export-pohoda" data-crm-id="<?php echo $inv_id; ?>">
                                                                <i class="fas fa-file-export me-2 text-white-75" aria-hidden="true"></i><?php echo __('export_pohoda'); ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <button type="button" class="dropdown-item" data-crm-action="export-s3" data-crm-id="<?php echo $inv_id; ?>">
                                                                <i class="fas fa-file-csv me-2 text-white-75" aria-hidden="true"></i><?php echo __('export_s3'); ?>
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <button type="button" class="dropdown-item text-danger" data-crm-action="delete-invoice" data-crm-id="<?php echo $inv_id; ?>">
                                                                <i class="fas fa-trash me-2" aria-hidden="true"></i><?php echo __('delete'); ?>
                                                            </button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $active_tab === 'stats' ? 'show active' : ''; ?>" id="stats-pane" role="tabpanel" aria-labelledby="stats-tab">
            <div class="row g-3">
                <?php if (empty($invoice_stats)): ?>
                    <div class="col-12">
                        <div class="glass-panel p-5 text-center text-white-75">
                            <i class="fas fa-chart-pie fa-3x mb-3 d-block opacity-25" aria-hidden="true"></i>
                            <?php echo e(__('no_invoices')); ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($metric_order as $status_key):
                        if (!isset($stats_by_status[$status_key])) {
                            continue;
                        }
                        $s = $stats_by_status[$status_key];
                    ?>
                    <div class="col-sm-6 col-xl-3">
                        <div class="metric-card metric-card--accounting">
                            <div class="metric-label"><?php echo e(__('status_' . ($status_key === 'issued' ? 'invoice_issued' : $status_key))); ?></div>
                            <div class="metric-value financial-number">
                                <?php echo number_format((float)$s['total'], 2, '.', ' '); ?>
                                <small class="metric-currency">Kč</small>
                            </div>
                            <div class="metric-meta">
                                <?php echo getInvoiceStatusBadge($status_key); ?>
                                <span class="ms-2"><?php echo (int)$s['count']; ?> ks</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($invoice_stats as $s):
                        if (in_array($s['status'], $metric_order, true)) {
                            continue;
                        }
                    ?>
                    <div class="col-sm-6 col-xl-3">
                        <div class="metric-card metric-card--accounting">
                            <div class="metric-label"><?php echo e(__('status_' . $s['status'])); ?></div>
                            <div class="metric-value financial-number">
                                <?php echo number_format((float)$s['total'], 2, '.', ' '); ?>
                                <small class="metric-currency">Kč</small>
                            </div>
                            <div class="metric-meta">
                                <?php echo getInvoiceStatusBadge($s['status']); ?>
                                <span class="ms-2"><?php echo (int)$s['count']; ?> ks</span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Invoice Modal (Create/Edit) -->
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-labelledby="invModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content glass-card border-secondary text-white">
            <form id="invoiceForm" method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_invoice">
                <input type="hidden" name="id" id="inv_id">
                <input type="hidden" name="order_id" id="inv_order_id">
                <input type="hidden" name="is_vat_payer" id="inv_is_vat_payer">
                <div class="modal-header">
                    <h5 class="modal-title" id="invModalTitle"><?php echo __('new_invoice'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label" for="inv_number"><?php echo __('invoice_number'); ?></label>
                            <input type="text" name="invoice_number" id="inv_number" class="form-control" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="inv_customer"><?php echo __('customer'); ?></label>
                            <div class="input-group">
                                <select name="customer_id" id="inv_customer" class="form-select select2" required>
                                    <option value=""><?php echo __('search_placeholder'); ?></option>
                                    <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['company'] ?: $c['first_name'] . ' ' . $c['last_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" data-crm-action="toggle-customer-override" title="<?php echo e(__('edit')); ?>">
                                    <i class="fas fa-user-edit" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div id="customer_override_fields" class="col-12 accounting-override-panel" hidden>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="inv_cust_name">Customer Name (Override)</label>
                                    <input type="text" name="cust_name" id="inv_cust_name" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="inv_cust_ico">ICO (Override)</label>
                                    <input type="text" name="cust_ico" id="inv_cust_ico" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="inv_cust_dic">DIC (Override)</label>
                                    <input type="text" name="cust_dic" id="inv_cust_dic" class="form-control">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label" for="inv_cust_address">Address (Override)</label>
                                    <textarea name="cust_address" id="inv_cust_address" class="form-control" rows="2"></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="from_order_id">Create from Order ID</label>
                            <div class="input-group">
                                <input type="number" id="from_order_id" class="form-control" placeholder="Order ID">
                                <button type="button" class="btn btn-outline-secondary" data-crm-action="load-from-order" title="Load">
                                    <i class="fas fa-download" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label" for="inv_date_issue"><?php echo __('date_issue'); ?></label>
                            <input type="date" name="date_issue" id="inv_date_issue" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="inv_date_tax"><?php echo __('date_tax'); ?></label>
                            <input type="date" name="date_tax" id="inv_date_tax" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="inv_date_due"><?php echo __('date_due'); ?></label>
                            <input type="date" name="date_due" id="inv_date_due" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="inv_status"><?php echo __('status'); ?></label>
                            <select name="status" id="inv_status" class="form-select">
                                <option value="draft"><?php echo __('status_draft'); ?></option>
                                <option value="issued" selected><?php echo __('status_invoice_issued'); ?></option>
                                <option value="paid"><?php echo __('status_paid'); ?></option>
                                <option value="overdue"><?php echo __('status_overdue'); ?></option>
                                <option value="cancelled"><?php echo __('status_cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="inv_payment_method"><?php echo __('payment_method'); ?></label>
                            <select name="payment_method" id="inv_payment_method" class="form-select">
                                <option value="bank_transfer"><?php echo __('bank_transfer'); ?></option>
                                <option value="cash"><?php echo __('cash'); ?></option>
                                <option value="card"><?php echo __('card'); ?></option>
                                <option value="cod"><?php echo __('cod_payment'); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3 d-flex justify-content-between align-items-center">
                        <h6 class="section-kicker mb-0"><?php echo __('parts_list'); ?></h6>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-crm-action="add-invoice-item">
                            <i class="fas fa-plus" aria-hidden="true"></i> <?php echo __('add_part'); ?>
                        </button>
                    </div>

                    <div class="table-responsive accounting-items-table-wrap">
                        <table class="table align-middle mb-0" id="itemsTable">
                            <thead>
                                <tr>
                                    <th><?php echo __('item_name'); ?></th>
                                    <th data-col="qty"><?php echo __('quantity'); ?></th>
                                    <th data-col="unit"><?php echo __('unit_label'); ?></th>
                                    <th data-col="price"><?php echo __('price_no_vat'); ?></th>
                                    <th data-col="vat"><?php echo __('vat_rate'); ?></th>
                                    <th data-col="actions"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Items will be added here -->
                            </tbody>
                        </table>
                    </div>

                    <div class="row mt-3 g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="inv_notes">Notes (internal or footer)</label>
                            <textarea name="notes" id="inv_notes" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <div class="accounting-totals-card">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-white-75"><?php echo __('subtotal'); ?></span>
                                    <span id="subtotal_val" class="financial-number">0.00 Kč</span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-white-75">VAT</span>
                                    <span id="vat_val" class="financial-number">0.00 Kč</span>
                                </div>
                                <hr class="my-2 border-secondary opacity-25">
                                <div class="d-flex justify-content-between align-items-baseline">
                                    <span class="fw-bold"><?php echo __('total_amount'); ?></span>
                                    <span id="total_val" class="financial-number fs-5 fw-bold">0.00 Kč</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Accounting Settings Modal -->
<div class="modal fade" id="accSettingsModal" tabindex="-1" aria-labelledby="accSettingsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content glass-card border-secondary text-white">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="accSettingsTitle"><?php echo __('acc_settings'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo e(__('close')); ?>"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('company_name'); ?></label>
                            <input type="text" name="acc_company_name" class="form-control" value="<?php echo e(get_setting('acc_company_name')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('ico'); ?></label>
                            <input type="text" name="acc_ico" class="form-control" value="<?php echo e(get_setting('acc_ico')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo __('dic'); ?></label>
                            <input type="text" name="acc_dic" class="form-control" value="<?php echo e(get_setting('acc_dic')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('address'); ?></label>
                            <textarea name="acc_address" class="form-control" rows="2"><?php echo e(get_setting('acc_address')); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('trade_register'); ?></label>
                            <input type="text" name="acc_trade_register" class="form-control" value="<?php echo e(get_setting('acc_trade_register')); ?>" placeholder="<?php echo e(__('trade_register_placeholder')); ?>">
                        </div>
                        <div class="col-12"><hr class="border-secondary opacity-25 my-1"></div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('bank_label'); ?></label>
                            <input type="text" name="acc_bank_name" class="form-control" value="<?php echo e(get_setting('acc_bank_name')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('account_number'); ?></label>
                            <input type="text" name="acc_bank_account" class="form-control" value="<?php echo e(get_setting('acc_bank_account')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IBAN</label>
                            <input type="text" name="acc_iban" class="form-control" value="<?php echo e(get_setting('acc_iban')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">SWIFT</label>
                            <input type="text" name="acc_swift" class="form-control" value="<?php echo e(get_setting('acc_swift')); ?>">
                        </div>
                        <div class="col-12"><hr class="border-secondary opacity-25 my-1"></div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('invoice_prefix'); ?></label>
                            <input type="text" name="acc_invoice_prefix" class="form-control" value="<?php echo e(get_setting('acc_invoice_prefix', date('Y'))); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo __('vat_rate'); ?></label>
                            <input type="number" name="acc_vat_rate" class="form-control" value="<?php echo e(get_setting('acc_vat_rate', '21')); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="acc_is_vat_payer" value="1" id="vatPayerCheck" <?php echo get_setting('acc_is_vat_payer', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="vatPayerCheck"><?php echo __('vat_payer'); ?></label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-text small mt-0 mb-2">
                                <i class="fas fa-info-circle me-1" aria-hidden="true"></i> <?php echo e(__('vat_info')); ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="acc_auto_create_invoice" value="1" id="autoCreateInv" <?php echo get_setting('acc_auto_create_invoice', '0') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="autoCreateInv">
                                    <?php echo __('auto_invoice_completed'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-12"><hr class="border-secondary opacity-25 my-1"></div>
                        <div class="col-12">
                            <div class="section-kicker mb-1">MyInvoice.cz API</div>
                            <div class="form-text">API token is read from <code>MYINVOICE_API_TOKEN</code> in <code>.env</code>.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">API base URL</label>
                            <input type="url" name="myinvoice_api_base_url" class="form-control" value="<?php echo e(get_setting('myinvoice_api_base_url', getenv('MYINVOICE_API_BASE_URL') ?: 'https://fakturace.43.157.31.121.sslip.io')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Country ID</label>
                            <input type="number" name="myinvoice_default_country_id" class="form-control" min="1" value="<?php echo e(get_setting('myinvoice_default_country_id', getenv('MYINVOICE_DEFAULT_COUNTRY_ID') ?: '1')); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Fallback ZIP</label>
                            <input type="text" name="myinvoice_default_zip" class="form-control" value="<?php echo e(get_setting('myinvoice_default_zip', getenv('MYINVOICE_DEFAULT_ZIP') ?: '11000')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fallback street</label>
                            <input type="text" name="myinvoice_default_street" class="form-control" value="<?php echo e(get_setting('myinvoice_default_street', getenv('MYINVOICE_DEFAULT_STREET') ?: '-')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fallback city</label>
                            <input type="text" name="myinvoice_default_city" class="form-control" value="<?php echo e(get_setting('myinvoice_default_city', getenv('MYINVOICE_DEFAULT_CITY') ?: 'Praha')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fallback email</label>
                            <input type="email" name="myinvoice_fallback_email" class="form-control" value="<?php echo e(get_setting('myinvoice_fallback_email', getenv('MYINVOICE_FALLBACK_EMAIL') ?: '')); ?>">
                            <div class="form-text">Used only when a CRM customer has no email. Leave empty to fail safely instead of creating invoices with a placeholder address.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="myinvoice_enabled" value="1" id="myinvoiceEnabled" <?php echo get_setting('myinvoice_enabled', '1') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="myinvoiceEnabled">Sync auto-created invoices to MyInvoice.cz</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="myinvoice_auto_issue" value="1" id="myinvoiceAutoIssue" <?php echo get_setting('myinvoice_auto_issue', '1') == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="myinvoiceAutoIssue">Issue invoice after draft creation</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo __('cancel_btn'); ?></button>
                    <button type="submit" name="save_acc_settings" class="btn btn-primary"><?php echo __('save_btn'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo e(crmCspNonce()); ?>">
let invModal;

document.addEventListener('DOMContentLoaded', function() {
    const invModalEl = document.getElementById('invoiceModal');
    if (invModalEl) {
        invModal = new bootstrap.Modal(invModalEl);
    }
    
    // UI reaction to VAT payer toggle in settings (if modal is open)
    const vatToggle = document.getElementById('vatPayerCheck');
    if (vatToggle) {
        vatToggle.addEventListener('change', function() {
            // This only affects the settings modal view if needed, 
            // but the main logic is in calcTotals which runs when invoice modal opens
        });
    }

    document.getElementById('invoiceForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        // Serialize items
        const items = [];
        document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
            const nameEl = tr.querySelector('.item-name');
            const qtyEl = tr.querySelector('.item-qty');
            const unitEl = tr.querySelector('.item-unit');
            const priceEl = tr.querySelector('.item-price');
            const vatEl = tr.querySelector('.item-vat');
            
            if (nameEl && qtyEl) {
                items.push({
                    name: nameEl.value,
                    quantity: qtyEl.value,
                    unit: unitEl ? unitEl.value : 'ks',
                    price: priceEl ? priceEl.value : 0,
                    vat_rate: vatEl ? vatEl.value : 0
                });
            }
        });
        formData.append('items', JSON.stringify(items));
        
        fetch('accounting_actions.php', {
            method: 'POST',
            body: formData
        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            } else {
                showAlert(data.error);
            }
        });
    });
});

function showNewInvoiceModal() {
    document.getElementById('invoiceForm').reset();
    document.getElementById('invoiceForm').dataset.invoiceType = 'invoice';
    document.querySelector('#invoiceForm [name="action"]').value = 'save_invoice';
    document.getElementById('inv_id').value = '';
    document.getElementById('inv_order_id').value = '';
    document.getElementById('inv_is_vat_payer').value = (document.getElementById('vatPayerCheck')?.checked ? '1' : '0');
    document.getElementById('invModalTitle').innerText = "<?php echo __('new_invoice'); ?>";
    document.querySelector('#itemsTable tbody').innerHTML = '';
    
    // Auto-generate number
    const prefix = <?php echo json_encode((string)get_setting('acc_invoice_prefix', date('Y')), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const nextNum = <?php echo json_encode(str_pad((count($invoices) + 1), 4, '0', STR_PAD_LEFT)); ?>;
    document.getElementById('inv_number').value = prefix + nextNum;
    
    addInvItem();
    invModal.show();
}

function addInvItem(data = {}) {
    const tbody = document.querySelector('#itemsTable tbody');
    const tr = document.createElement('tr');
    const isVatPayer = document.getElementById('vatPayerCheck')?.checked ||
        <?php echo json_encode((string)get_setting('acc_is_vat_payer', '0')); ?> === '1';
    const defaultVatRate = <?php echo json_encode((string)get_setting('acc_vat_rate', '21')); ?>;
    
    tr.innerHTML = `
        <td><input type="text" class="form-control form-control-sm item-name" required></td>
        <td><input type="number" step="0.01" min="0.01" class="form-control form-control-sm item-qty" data-crm-change-action="calc-totals"></td>
        <td><input type="text" class="form-control form-control-sm item-unit"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm item-price" data-crm-change-action="calc-totals"></td>
        <td><input type="number" min="0" max="100" class="form-control form-control-sm item-vat" data-crm-change-action="calc-totals"></td>
        <td><button type="button" class="btn btn-sm btn-link text-danger" data-crm-action="remove-invoice-item"><i class="fas fa-times"></i></button></td>
    `;
    tr.querySelector('.item-name').value = data.name || data.item_name || '';
    tr.querySelector('.item-qty').value = data.quantity || '1';
    tr.querySelector('.item-unit').value = data.unit || 'ks';
    const isCreditNote = document.getElementById('invoiceForm').dataset.invoiceType === 'credit_note'
        || Number(data.price) < 0;
    tr.querySelector('.item-price').value = data.price || '0';
    if (isCreditNote) {
        tr.querySelector('.item-price').removeAttribute('min');
        tr.querySelector('.item-price').setAttribute('max', '0');
    }
    tr.querySelector('.item-vat').value = data.vat_rate ?? defaultVatRate;
    tr.querySelector('.item-vat').closest('td').style.display = isVatPayer ? '' : 'none';
    tbody.appendChild(tr);
    calcTotals();
}

function calcTotals() {
    let subtotal = 0;
    let vatTotal = 0;
    const isVatPayer = document.getElementById('vatPayerCheck')?.checked ||
        <?php echo json_encode((string)get_setting('acc_is_vat_payer', '0')); ?> === '1';
    
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
        const qty = parseFloat(tr.querySelector('.item-qty').value) || 0;
        const price = parseFloat(tr.querySelector('.item-price').value) || 0;
        const vatRate = isVatPayer ? (parseFloat(tr.querySelector('.item-vat').value) || 0) : 0;
        
        const lineSub = qty * price;
        const lineVat = lineSub * (vatRate / 100);
        
        subtotal += lineSub;
        vatTotal += lineVat;
    });
    
    document.getElementById('subtotal_val').innerText = subtotal.toFixed(2) + ' Kč';
    document.getElementById('vat_val').innerText = (isVatPayer ? vatTotal.toFixed(2) : '0.00') + ' Kč';
    document.getElementById('total_val').innerText = (subtotal + (isVatPayer ? vatTotal : 0)).toFixed(2) + ' Kč';
    
    // Hide/Show VAT columns in modal
    const vatTh = document.querySelector('#itemsTable thead th:nth-child(5)');
    if (vatTh) vatTh.style.display = isVatPayer ? '' : 'none';
    
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
        const vatTd = tr.querySelector('.item-vat').closest('td');
        if (vatTd) vatTd.style.display = isVatPayer ? '' : 'none';
    });
    
    // Hide VAT rows in total card
    const vatRow = document.getElementById('vat_val').closest('.d-flex');
    if (vatRow) vatRow.style.display = isVatPayer ? 'flex' : 'none';
    const subtotalRow = document.getElementById('subtotal_val').closest('.d-flex');
    if (subtotalRow) subtotalRow.style.display = isVatPayer ? 'flex' : 'none';
}

function editInvoice(id) {
    fetch('accounting_actions.php?action=get_invoice&id=' + id)
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            const data = res.data;
            document.getElementById('invoiceForm').reset();
            document.getElementById('invoiceForm').dataset.invoiceType = data.invoice_type || 'invoice';
            
            // Re-set action because reset() clears it
            document.querySelector('#invoiceForm [name="action"]').value = 'save_invoice';
            
            document.getElementById('inv_id').value = data.id;
            document.getElementById('inv_order_id').value = data.order_id || '';
            document.getElementById('inv_is_vat_payer').value = data.is_vat_payer || '0';
            document.getElementById('inv_number').value = data.invoice_number;
            document.getElementById('inv_customer').value = data.customer_id;
            
            // Trigger select2 update if used
            if (typeof jQuery !== 'undefined' && jQuery('#inv_customer').data('select2')) {
                jQuery('#inv_customer').val(data.customer_id).trigger('change');
            }

            document.getElementById('inv_date_issue').value = data.date_issue;
            document.getElementById('inv_date_tax').value = data.date_tax;
            document.getElementById('inv_date_due').value = data.date_due;
            document.getElementById('inv_status').value = data.status;
            document.getElementById('inv_payment_method').value = data.payment_method || 'bank_transfer';
            
            // Set overrides if any
            document.getElementById('inv_cust_name').value = data.cust_name_override || '';
            document.getElementById('inv_cust_ico').value = data.cust_ico_override || '';
            document.getElementById('inv_cust_dic').value = data.cust_dic_override || '';
            document.getElementById('inv_cust_address').value = data.cust_address_override || '';
            document.getElementById('inv_notes').value = data.notes || '';
            
            const overridePanel = document.getElementById('customer_override_fields');
            if (overridePanel) {
                overridePanel.hidden = !(data.cust_name_override || data.cust_ico_override || data.cust_address_override);
            }

            if (data.order_id) {
                document.getElementById('from_order_id').value = data.order_id;
            } else {
                document.getElementById('from_order_id').value = '';
            }
            
            const tbody = document.querySelector('#itemsTable tbody');
            tbody.innerHTML = '';
            data.items.forEach(item => addInvItem(item));
            
            document.getElementById('invModalTitle').innerText = "<?php echo __('edit_invoice'); ?>";
            invModal.show();
        }
    });
}

function loadFromOrder() {
    const orderId = document.getElementById('from_order_id').value;
    if (!orderId) return;
    
    fetch('accounting_actions.php?action=get_order_data&order_id=' + orderId)
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            document.getElementById('inv_customer').value = res.data.customer_id;
            document.getElementById('inv_order_id').value = orderId;
            document.getElementById('inv_is_vat_payer').value = res.data.is_vat_payer ? '1' : '0';
            
            // Trigger select2 if present
            if (typeof jQuery !== 'undefined' && jQuery('#inv_customer').data('select2')) {
                jQuery('#inv_customer').val(res.data.customer_id).trigger('change');
            }

            const tbody = document.querySelector('#itemsTable tbody');
            tbody.innerHTML = '';
            res.data.items.forEach(item => addInvItem({
                item_name: item.name,
                quantity: item.quantity,
                unit: item.unit,
                price: item.price,
                vat_rate: item.vat_rate
            }));
        } else {
            showAlert(res.error);
        }
    });
}

function deleteInvoice(id) {
    showConfirm('Delete this invoice?', function() {
        const formData = new FormData();
        formData.append('action', 'delete_invoice');
        formData.append('id', id);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('accounting_actions.php', { method: 'POST', body: formData }).then(() => location.reload());
    });
}

function createCreditNote(id) {
    showConfirm('Create a Credit Note (Opravný daňový doklad) from this invoice?', function() {
        const formData = new FormData();
        formData.append('action', 'create_credit_note');
        formData.append('id', id);
        formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('accounting_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                location.reload();
            } else {
                showAlert(res.error);
            }
        });
    });
}

function toggleCustomerOverride() {
    const div = document.getElementById('customer_override_fields');
    if (!div) return;
    div.hidden = !div.hidden;
}

function exportPohoda(id) {
    const formData = new FormData();
    formData.append('action', 'export_pohoda');
    formData.append('id', id);
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');

    fetch('accounting_actions.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) triggerDownload('temp/exports/' + res.file);
        else showAlert(res.error || 'Export failed');
    });
}

function exportS3(id) {
    const formData = new FormData();
    formData.append('action', 'export_s3money');
    formData.append('id', id);
    formData.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');

    fetch('accounting_actions.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(res => {
        if (res.success) triggerDownload('temp/exports/' + res.file);
        else showAlert(res.error || 'Export failed');
    });
}

// Expose page actions for shared data-crm-action delegation in main.js
window.showNewInvoiceModal = showNewInvoiceModal;
window.addInvItem = addInvItem;
window.calcTotals = calcTotals;
window.editInvoice = editInvoice;
window.loadFromOrder = loadFromOrder;
window.deleteInvoice = deleteInvoice;
window.createCreditNote = createCreditNote;
window.toggleCustomerOverride = toggleCustomerOverride;
window.exportPohoda = exportPohoda;
window.exportS3 = exportS3;
</script>

<?php require_once 'includes/footer.php'; ?>

