<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$customers = [];
if (isset($pdo)) {
    try {
        $limit = 50; // Show 50 customers per page
        $page = isset($_GET['cp']) && is_numeric($_GET['cp']) ? (int)$_GET['cp'] : 1;
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        $search = $_GET['search'] ?? '';
        if (!empty($search)) {
            $search = trim($search);
            $exact_id_filter = is_numeric($search) ? " OR id = ?" : "";
            
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ? OR ico LIKE ? OR dic LIKE ? OR company LIKE ?$exact_id_filter)");
            $term = "%$search%";
            $params = [$term, $term, $term, $term, $term, $term, $term];
            if (is_numeric($search)) $params[] = (int)$search;
            $count_stmt->execute($params);
            $total_customers = $count_stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT * FROM customers WHERE 
                (first_name LIKE ? OR 
                last_name LIKE ? OR 
                phone LIKE ? OR 
                email LIKE ? OR
                ico LIKE ? OR
                dic LIKE ? OR
                company LIKE ?$exact_id_filter)
                ORDER BY (CASE WHEN id = ? THEN 1 ELSE 2 END), last_name ASC LIMIT $limit OFFSET $offset");
            $search_id = is_numeric($search) ? (int)$search : 0;
            $exec_params = $params;
            $exec_params[] = $search_id;
            $stmt->execute($exec_params);
        } else {
            $total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
            $stmt = $pdo->query("SELECT * FROM customers ORDER BY last_name ASC LIMIT $limit OFFSET $offset");
        }
        $customers = $stmt->fetchAll();
        $total_pages = ceil($total_customers / $limit);
        
        // Pre-count orders for these customers to fix N+1
        $order_counts = [];
        if (!empty($customers)) {
            $customer_ids = array_column($customers, 'id');
            $placeholders = implode(',', array_fill(0, count($customer_ids), '?'));
            $c_stmt = $pdo->prepare("SELECT customer_id, COUNT(*) as cnt FROM orders WHERE customer_id IN ($placeholders) GROUP BY customer_id");
            $c_stmt->execute($customer_ids);
            while ($row = $c_stmt->fetch()) {
                $order_counts[$row['customer_id']] = (int)$row['cnt'];
            }
        }

    } catch (PDOException $e) { }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?php echo __('customers_db'); ?></h2>
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
        <div class="table-responsive">
            <table class="table table-hover align-middle">
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
                            <td colspan="7" class="text-center py-4 text-muted"><?php echo __('no_customers_found'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr>
                            <td>#<?php echo $customer['id']; ?></td>
                            <td>
                                <strong>
                                    <?php 
                                    if ($customer['customer_type'] == 'company') {
                                        echo htmlspecialchars($customer['company'] ?: $customer['last_name']);
                                        echo ' <small class="text-muted">(Firma)</small>';
                                    } else {
                                        echo htmlspecialchars($customer['last_name'] . ' ' . $customer['first_name']);
                                    }
                                    ?>
                                </strong>
                                <?php if ($customer['ico']): ?>
                                    <div class="small text-muted">IČO: <?php echo htmlspecialchars($customer['ico']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                            <td><?php echo htmlspecialchars($customer['address']); ?></td>
                            <td>
                                <?php 
                                $count = $order_counts[$customer['id']] ?? 0;
                                ?>
                                <button class="btn btn-sm <?php echo $count > 0 ? 'btn-primary' : 'btn-outline-secondary'; ?> rounded-pill px-3"
                                        data-customer-name="<?php echo htmlspecialchars(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"
                                        onclick="showCustomerOrders(<?php echo (int)$customer['id']; ?>, this.dataset.customerName)"
                                        <?php echo $count == 0 ? 'disabled' : ''; ?>>
                                    <?php echo $count; ?>
                                </button>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-outline-primary"><i class="fas fa-edit"></i></a>
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
<div class="modal fade" id="customerOrdersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('customer_orders_title'); ?>: <span id="modalCustomerName"></span></h5>
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

<script>
function showCustomerOrders(id, name) {
    $('#modalCustomerName').text(name);
    $('#customerOrdersList').html('<tr><td colspan="5" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> <?php echo __('loading_text'); ?></td></tr>');
    var myModal = new bootstrap.Modal(document.getElementById('customerOrdersModal'));
    myModal.show();

    $.ajax({
        url: 'api/get_customer_orders.php',
        method: 'GET',
        dataType: 'json',
        data: { customer_id: id }
    }).done(function(res) {
        if (res && res.success) {
            var html = '';
            if (!Array.isArray(res.orders) || res.orders.length === 0) {
                html = '<tr><td colspan="5" class="text-center py-4"><?php echo __('orders_not_found'); ?></td></tr>';
            } else {
                function escapeHtml(text) {
                    if (text == null) return '';
                    return $('<div>').text(text).html();
                }
                res.orders.forEach(function(o) {
                    html += '<tr>';
                    html += '<td class="ps-3"><span class="fw-bold">#' + escapeHtml(o.id) + '</span></td>';
                    html += '<td>' + escapeHtml(o.device_brand) + ' ' + escapeHtml(o.device_model) + '</td>';
                    html += '<td>' + escapeHtml(o.status) + '</td>';
                    html += '<td>' + escapeHtml(new Date(o.created_at).toLocaleDateString()) + '</td>';
                    html += '<td class="text-end pe-3"><a href="view_order.php?id=' + escapeHtml(o.id) + '" class="btn btn-sm btn-outline-primary"><?php echo __('open_btn'); ?></a></td>';
                    html += '</tr>';
                });
            }
            $('#customerOrdersList').html(html);
        } else {
            const msg = (res && res.message) ? res.message : '<?php echo __('error_loading_data'); ?>';
            $('#customerOrdersList').html('<tr><td colspan="5" class="text-center py-4 text-danger">' + $('<div>').text(msg).html() + '</td></tr>');
        }
    }).fail(function(xhr) {
        const msg = (xhr && xhr.responseText) ? xhr.responseText : '<?php echo __('network_error'); ?>';
        $('#customerOrdersList').html('<tr><td colspan="5" class="text-center py-4 text-danger"><?php echo __('error_loading_data'); ?></td></tr>');
        showAlert($('<div>').text(msg).html());
    });
}
</script>

<!-- New Customer Modal -->
<div class="modal fade" id="newCustomerModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="api/add_customer.php" method="POST" id="newCustomerForm">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('add_customer'); ?></h5>
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
                                <button class="btn btn-info text-white" type="button" id="btn_fetch_ares">
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

<script>
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
        if (!ico) return showAlert('<?php echo __('enter_ico_prompt'); ?>');
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
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
                    showAlert('<?php echo __('ares_data_not_found'); ?>');
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                showAlert('<?php echo __('ares_fetch_error'); ?>');
            }
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

