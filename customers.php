<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
// Page-level guard independent of header.php's permission map (defense in depth).
if (!hasPermission('edit_customers')) {
    header('Location: index.php');
    exit;
}
require_once 'includes/header.php';

$customers = [];
$total_customers = 0;
$total_pages = 1;
$page = 1;
$order_counts = [];

if (isset($pdo)) {
    try {
        $limit = 50;
        $page = isset($_GET['cp']) && is_numeric($_GET['cp']) ? (int)$_GET['cp'] : 1;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        $tech_scoped = isTechnicianScoped();
        $tech_id = currentTechnicianId();

        $search = trim($_GET['search'] ?? '');
        $where_parts = [];
        $params = [];

        if ($search !== '') {
            $term = "%$search%";
            $search_clause = '(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ? OR ico LIKE ? OR dic LIKE ? OR company LIKE ?';
            $params = [$term, $term, $term, $term, $term, $term, $term];
            if (is_numeric($search)) {
                $search_clause .= ' OR id = ?';
                $params[] = (int)$search;
            }
            $search_clause .= ')';
            $where_parts[] = $search_clause;
        }

        if ($tech_scoped) {
            $where_parts[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id AND o.technician_id = ?)';
            $params[] = $tech_id;
        }

        $where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where_sql");
        $count_stmt->execute($params);
        $total_customers = (int)$count_stmt->fetchColumn();

        if ($search !== '') {
            $search_id = is_numeric($search) ? (int)$search : 0;
            $order_sql = 'ORDER BY (CASE WHEN id = ? THEN 1 ELSE 2 END), last_name ASC';
            $exec_params = array_merge($params, [$search_id]);
        } else {
            $order_sql = 'ORDER BY last_name ASC';
            $exec_params = $params;
        }

        $stmt = $pdo->prepare(
            "SELECT * FROM customers $where_sql $order_sql LIMIT " . (int)$limit . " OFFSET " . (int)$offset
        );
        $stmt->execute($exec_params);
        $customers = $stmt->fetchAll();
        $total_pages = $total_customers > 0 ? (int)ceil($total_customers / $limit) : 1;

        // Pre-count orders (scoped to this technician when applicable)
        if (!empty($customers)) {
            $customer_ids = array_column($customers, 'id');
            $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
            if ($tech_scoped) {
                $c_stmt = $pdo->prepare(
                    "SELECT customer_id, COUNT(*) as cnt FROM orders
                     WHERE customer_id IN ($placeholders) AND technician_id = ?
                     GROUP BY customer_id"
                );
                $c_stmt->execute(array_merge($customer_ids, [$tech_id]));
            } else {
                $c_stmt = $pdo->prepare(
                    "SELECT customer_id, COUNT(*) as cnt FROM orders
                     WHERE customer_id IN ($placeholders)
                     GROUP BY customer_id"
                );
                $c_stmt->execute($customer_ids);
            }
            while ($row = $c_stmt->fetch()) {
                $order_counts[$row['customer_id']] = (int)$row['cnt'];
            }
        }

    } catch (PDOException $e) {
        error_log('customers.php: ' . $e->getMessage());
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?php echo __('customers_db'); ?></h1>
    <div class="d-flex gap-2">
        <?php if(!empty($_GET['search'])): ?>
            <a href="customers.php" class="btn btn-outline-secondary"><?php echo __('reset_search'); ?></a>
        <?php endif; ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCustomerModal">
            <i class="fas fa-user-plus me-2"></i> <?php echo __('add_customer'); ?>
        </button>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?php echo __('customer_added_success'); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive table-scroll-touch">
            <table class="table table-hover align-middle table-mobile-cards">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php echo __('name_col'); ?></th>
                        <th><?php echo __('phone'); ?></th>
                        <th>Email</th>
                        <th><?php echo __('address'); ?></th>
                        <th><?php echo __('orders'); ?></th>
                        <th><?php echo __('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-state__mark" aria-hidden="true"></div>
                                    <p class="mb-0"><?php echo e(__('no_customers_found')); ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td data-label="ID">#<?php echo $customer['id']; ?></td>
                            <td data-label="<?php echo e(__('name_col')); ?>">
                                <strong>
                                    <?php 
                                    if ($customer['customer_type'] == 'company') {
                                        echo htmlspecialchars($customer['company'] ?: $customer['last_name']);
                                        echo ' <small class="text-muted">(' . e(__('company_marker')) . ')</small>';
                                    } else {
                                        echo htmlspecialchars($customer['last_name'] . ' ' . $customer['first_name']);
                                    }
                                    ?>
                                </strong>
                                <?php if ($customer['ico']): ?>
                                    <div class="small text-muted">IČO: <?php echo htmlspecialchars($customer['ico']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?php echo e(__('phone')); ?>"><?php echo htmlspecialchars($customer['phone']); ?></td>
                            <td data-label="Email"><?php echo htmlspecialchars($customer['email']); ?></td>
                            <td data-label="<?php echo e(__('address')); ?>"><?php echo htmlspecialchars($customer['address']); ?></td>
                            <td data-label="<?php echo e(__('orders')); ?>">
                                <?php 
                                $count = $order_counts[$customer['id']] ?? 0;
                                ?>
                                <button class="btn btn-sm <?php echo $count > 0 ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3"
                                        data-customer-name="<?php echo htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"
                                        data-crm-action="show-customer-orders"
                                        data-crm-id="<?php echo (int)$customer['id']; ?>"
                                        <?php echo $count == 0 ? 'disabled' : ''; ?>>
                                    <?php echo $count; ?>
                                </button>
                            </td>
                            <td class="mobile-row-actions" data-label="">
                                <div class="btn-group btn-group-sm">
                                    <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-primary" aria-label="<?php echo e(__('edit')); ?>"><i class="fas fa-edit" aria-hidden="true"></i></a>
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

<!-- Pagination -->
<?php if (isset($total_pages) && $total_pages > 1): ?>
<nav class="mt-4">
    <ul class="pagination justify-content-center">
        <?php 
        $params = $_GET;
        unset($params['cp']);
        $query_str = http_build_query($params);
        $url_prefix = $query_str ? "&$query_str" : "";
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?cp=<?php echo $page - 1 . $url_prefix; ?>"><i class="fas fa-chevron-left"></i></a>
        </li>
        <?php 
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        if ($start > 1) {
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?cp=1'.$url_prefix.'">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
        }
        for ($i = $start; $i <= $end; $i++): 
        ?>
            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?cp=<?php echo $i . $url_prefix; ?>"><?php echo $i; ?></a>
            </li>
        <?php endfor; 
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link border-0 bg-transparent">...</span></li>';
            echo '<li class="page-item"><a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?cp='.$total_pages.$url_prefix.'">'.$total_pages.'</a></li>';
        }
        ?>
        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <a class="page-link border-0 shadow-sm rounded-circle mx-1" href="?cp=<?php echo $page + 1 . $url_prefix; ?>"><i class="fas fa-chevron-right"></i></a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<!-- Customer Orders Modal -->
<div class="modal fade" id="customerOrdersModal" tabindex="-1" aria-labelledby="customerOrdersModalTitle">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerOrdersModalTitle"><?php echo __('customer_orders_title'); ?>: <span id="modalCustomerName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="max-height: 400px; overflow-y: auto;">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-dark sticky-top">
                            <tr>
                                <th class="ps-3">ID</th>
                                <th><?php echo __('device'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th><?php echo __('date_issue'); ?></th>
                                <th class="text-end pe-3"><?php echo __('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="customerOrdersList">
                            <!-- Loaded via JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $crmJsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE; ?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
const CUSTOMER_ORDERS_I18N = {
    loading: <?php echo json_encode(__('loading_text'), $crmJsFlags); ?>,
    empty: <?php echo json_encode(__('orders_not_found'), $crmJsFlags); ?>,
    open: <?php echo json_encode(__('open_btn'), $crmJsFlags); ?>,
    loadError: <?php echo json_encode(__('error_loading_data'), $crmJsFlags); ?>,
    networkError: <?php echo json_encode(__('network_error'), $crmJsFlags); ?>
};

function customerOrdersMessageRow(text, isError) {
    const $cell = $('<td colspan="5">');
    if (isError) {
        $cell.addClass('text-center py-4 text-danger').text(text);
    } else {
        $cell.append(
            $('<div class="empty-state">')
                .append('<div class="empty-state__mark" aria-hidden="true"></div>')
                .append($('<p class="mb-0">').text(text))
        );
    }
    return $('<tr>').append($cell);
}

function customerOrderRow(order) {
    const id = Number.parseInt(order.id, 10);
    const variant = /^[a-z-]+$/.test(String(order.status_variant || '')) ? order.status_variant : 'closed';
    const created = order.created_at ? new Date(String(order.created_at).replace(' ', 'T')) : null;
    const $row = $('<tr>');
    $row.append($('<td class="ps-3">').append($('<span class="fw-bold">').text('#' + id)));
    $row.append($('<td>').text([order.device_brand, order.device_model].filter(Boolean).join(' ')));
    $row.append($('<td>').append(
        $('<span>').addClass('status-pill status-pill--' + variant).text(order.status_label || order.status || '')
    ));
    $row.append($('<td>').text(created && !Number.isNaN(created.getTime()) ? created.toLocaleDateString() : ''));
    $row.append($('<td class="text-end pe-3">').append(
        $('<a class="btn btn-sm btn-outline-primary">').attr('href', 'view_order.php?id=' + id).text(CUSTOMER_ORDERS_I18N.open)
    ));
    return $row;
}

function showCustomerOrders(id, name) {
    const $list = $('#customerOrdersList');
    $('#modalCustomerName').text(name);
    $list.empty().append(
        $('<tr>').append(
            $('<td colspan="5" class="text-center py-4">')
                .append('<div class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></div> ')
                .append(document.createTextNode(CUSTOMER_ORDERS_I18N.loading))
        )
    );
    bootstrap.Modal.getOrCreateInstance(document.getElementById('customerOrdersModal')).show();

    $.ajax({
        url: 'api/get_customer_orders.php',
        method: 'GET',
        dataType: 'json',
        data: { customer_id: id }
    }).done(function(res) {
        $list.empty();
        if (!res || !res.success) {
            $list.append(customerOrdersMessageRow((res && res.message) ? res.message : CUSTOMER_ORDERS_I18N.loadError, true));
            return;
        }
        if (!Array.isArray(res.orders) || res.orders.length === 0) {
            $list.append(customerOrdersMessageRow(CUSTOMER_ORDERS_I18N.empty, false));
            return;
        }
        res.orders.forEach(function(order) {
            $list.append(customerOrderRow(order));
        });
    }).fail(function(xhr) {
        const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message)
            ? xhr.responseJSON.message
            : CUSTOMER_ORDERS_I18N.networkError;
        $list.empty().append(customerOrdersMessageRow(CUSTOMER_ORDERS_I18N.loadError, true));
        showAlert(msg);
    });
}
</script>

<!-- New Customer Modal -->
<div class="modal fade" id="newCustomerModal" tabindex="-1" aria-labelledby="newCustomerModalTitle">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="api/add_customer.php" method="POST" id="newCustomerForm">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="newCustomerModalTitle"><?php echo __('add_customer'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="customer_type" id="type_private" value="private" checked>
                            <label class="btn btn-outline-primary" for="type_private"><?php echo __('private_person'); ?></label>

                            <input type="radio" class="btn-check" name="customer_type" id="type_company" value="company">
                            <label class="btn btn-outline-primary" for="type_company"><?php echo __('company_entity'); ?></label>
                        </div>
                    </div>

                    <div id="company_fields" class="d-none border p-3 rounded bg-dark bg-opacity-25 border-secondary mb-3">
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('ico'); ?></label>
                            <div class="input-group">
                                <input type="text" name="ico" id="ico_input" class="form-control" placeholder="12345678">
                                <button class="btn btn-outline-secondary" type="button" id="btn_fetch_ares">
                                    <i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('company_name'); ?></label>
                            <input type="text" name="company_name" id="ares_name" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?php echo __('dic'); ?></label>
                            <input type="text" name="dic" id="ares_dic" class="form-control" placeholder="CZ12345678">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('client'); ?> (<?php echo __('name_col'); ?>)</label>
                            <input type="text" name="first_name" id="ares_first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo __('client'); ?> (<?php echo __('last_name_label'); ?>)</label>
                            <input type="text" name="last_name" id="ares_last_name" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('phone'); ?></label>
                            <input type="tel" name="phone" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo __('address'); ?></label>
                            <textarea name="address" id="ares_address" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?php echo e(crmCspNonce()); ?>">
$(document).ready(function() {
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#company_fields').removeClass('d-none');
            $('#ares_first_name').val('Firma');
            $('#ares_last_name').val('');
        } else {
            $('#company_fields').addClass('d-none');
            $('#ares_first_name').val('');
            $('#ares_last_name').val('');
        }
    });

    $('#btn_fetch_ares').on('click', function() {
        const ico = $('#ico_input').val().trim();
        if (!ico) return showAlert(<?php echo json_encode(__('enter_ico_prompt'), $crmJsFlags); ?>);
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1" aria-hidden="true"></i> ').append(document.createTextNode(<?php echo json_encode(__('fetch_ares'), $crmJsFlags); ?>));
                if (data && data.obchodniJmeno) {
                    $('#ares_name').val(data.obchodniJmeno);
                    $('#ares_last_name').val(data.obchodniJmeno);
                    $('#ares_first_name').val('Firma');
                    
                    if (data.dic) {
                        $('#ares_dic').val(data.dic);
                    }

                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#ares_address').val(addr.trim());
                    }
                } else {
                    showAlert(<?php echo json_encode(__('ares_data_not_found'), $crmJsFlags); ?>);
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1" aria-hidden="true"></i> ').append(document.createTextNode(<?php echo json_encode(__('fetch_ares'), $crmJsFlags); ?>));
                showAlert(<?php echo json_encode(__('ares_fetch_error'), $crmJsFlags); ?>);
            }
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
