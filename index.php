<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

// Filter for Dashboard
$filter_status = $_GET['filter'] ?? null;

// Permission check for stats - technicians only see their orders unless they have view_all_orders
$tech_cond = "";
if ($_SESSION['role'] == 'technician' && !hasPermission('view_all_orders')) {
    $tech_cond = " AND technician_id = " . (int)$_SESSION['tech_id'];
}

// Count for Stats
$new_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'New'" . $tech_cond)->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending Approval'" . $tech_cond)->fetchColumn();
$progress_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'In Progress'" . $tech_cond)->fetchColumn();
$ready_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Completed', 'Collected')" . $tech_cond)->fetchColumn();

// Online Techs (Last 5 minutes) - Admin or those with admin_access
$online_count = 0;
if (hasPermission('admin_access')) {
    $online_count = $pdo->query("SELECT COUNT(*) FROM technicians WHERE last_seen > (NOW() - INTERVAL 5 MINUTE) AND is_active = 1")->fetchColumn();
}

// Load technicians list once for new order modal
$techs_list = [];
try {
    $techs_list = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) {
    $techs_list = [];
}

$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

?>

<div class="row g-4 mb-4">
    <!-- Stat Cards -->
    <div class="col-12 col-sm-6 col-md-4 col-xl">
        <a href="?filter=New" class="text-decoration-none">
            <div class="card glass-card p-3 h-100 <?php echo $filter_status == 'New' ? 'border-primary border-2' : 'border-0'; ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-3">
                        <i class="fas fa-clipboard-list text-primary fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $new_count; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('new_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-4 col-xl">
        <a href="?filter=Pending Approval" class="text-decoration-none">
            <div class="card glass-card p-3 h-100 <?php echo $filter_status == 'Pending Approval' ? 'border-info border-2' : 'border-0'; ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-info bg-opacity-10 p-2 rounded-circle me-3">
                        <i class="fas fa-handshake text-info fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $pending_count; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('pending_approval_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-4 col-xl">
        <a href="?filter=In Progress" class="text-decoration-none">
            <div class="card glass-card p-3 h-100 <?php echo $filter_status == 'In Progress' ? 'border-warning border-2' : 'border-0'; ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-warning bg-opacity-10 p-2 rounded-circle me-3">
                        <i class="fas fa-spinner text-warning fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $progress_count; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('in_progress_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-4 col-xl">
        <a href="?filter=Completed" class="text-decoration-none">
            <div class="card glass-card p-3 h-100 <?php echo ($filter_status == 'Completed' || $filter_status == 'Collected') ? 'border-success border-2' : 'border-0'; ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-2 rounded-circle me-3">
                        <i class="fas fa-check-double text-success fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $ready_count; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('completed_orders'); ?></p>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-sm-6 col-md-4 col-xl">
        <?php if ($_SESSION['role'] == 'admin'): ?>
            <div class="card glass-card p-3 h-100 border-0" data-bs-toggle="tooltip" title="<?php echo __('online_techs_tooltip'); ?>">
                <div class="d-flex align-items-center">
                    <div class="bg-info bg-opacity-10 p-2 rounded-circle me-3">
                        <i class="fas fa-users text-info fa-xl"></i>
                    </div>
                    <div>
                        <h4 class="mb-0 fw-bold"><?php echo $online_count; ?></h4>
                        <p class="text-white-75 mb-0 small"><?php echo __('online_techs'); ?></p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card p-3 h-100 border-0 bg-dark bg-opacity-25 shadow-none">
                <div class="d-flex align-items-center justify-content-center h-100">
                    <img src="https://servis.expert/wp-content/uploads/2021/04/cropped-logo-servis-expert-1.png" style="max-height: 40px; opacity: 0.5;">
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card glass-card border-0 dashboard-orders-card">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php 
                    if ($filter_status == 'New') echo __('new_orders');
                    elseif ($filter_status == 'Pending Approval') echo __('pending_approval_orders');
                    elseif ($filter_status == 'In Progress') echo __('in_progress_orders');
                    elseif ($filter_status == 'Completed') echo __('completed_orders');
                    else echo __('recent_orders'); 
                    ?>
                </h5>
                <?php if ($filter_status): ?>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary"><?php echo __('show_all'); ?></a>
                <?php else: ?>
                    <a href="orders.php" class="btn btn-sm btn-primary"><?php echo __('all_orders'); ?></a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive dashboard-orders-table-wrap">
                    <table class="table table-hover align-middle mb-0 dashboard-orders-table">
                        <thead class="bg-transparent sticky-top" style="z-index: 10;">
                            <tr>
                                <th class="ps-4">ID</th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('device_model'); ?></th>
                                <th><?php echo __('problem'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="text-end pe-4"><?php echo __('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $search = $_GET['search'] ?? '';
                            
                            // Permission check for technicians
                            $tech_filter = "";
                            if ($_SESSION['role'] == 'technician' && !hasPermission('view_all_orders')) {
                                $tech_filter = " AND o.technician_id = " . (int)$_SESSION['tech_id'];
                            }
                            
                            $where_clause = " WHERE (1=1)" . $tech_filter;
                            $params = [];

                            if ($search) {
                                $search = trim($search);
                                $exact_id_filter = is_numeric($search) ? " OR o.id = ?" : "";
                                $where_clause .= " AND (o.id LIKE ? OR o.device_model LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?$exact_id_filter)";
                                $searchTerm = "%$search%";
                                array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                                if (is_numeric($search)) {
                                    $params[] = (int)$search;
                                }
                            }

                            if ($filter_status) {
                                if ($filter_status == 'Completed') {
                                    $where_clause .= " AND o.status IN ('Completed', 'Collected')";
                                } else {
                                    $where_clause .= " AND o.status = ?";
                                    $params[] = $filter_status;
                                }
                            }

                            $search_id = ($search && is_numeric($search)) ? (int)$search : 0;
                            $sql = "SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name 
                                    FROM orders o 
                                    JOIN customers c ON o.customer_id = c.id 
                                    LEFT JOIN technicians t ON o.technician_id = t.id" . 
                                    $where_clause . 
                                    " ORDER BY (CASE WHEN o.id = ? THEN 1 ELSE 2 END), o.created_at DESC LIMIT 15";
                            
                            $stmt = $pdo->prepare($sql);
                            // Add search_id to params for the ORDER BY clause
                            $exec_params = array_merge($params, [$search_id]);
                            $stmt->execute($exec_params);
                            
                            $orders_list = $stmt->fetchAll();
                            
                            $has_media_ids = [];
                            if (!empty($orders_list)) {
                                $order_ids = array_column($orders_list, 'id');
                                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                                $m_stmt = $pdo->prepare("SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id");
                                $m_stmt->execute($order_ids);
                                $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
                            }
                            
                            $found = false;
                            foreach($orders_list as $r):
                                $found = true;
                                $icon = getDeviceIcon($r['device_type']);

                                $has_media = isset($has_media_ids[$r['id']]);
                            ?>
                            <tr <?php if($r['priority'] == 'High') echo 'class="table-danger"'; ?>>
                                <td class="ps-4">
                                    <a href="view_order.php?id=<?php echo $r['id']; ?>" class="fw-bold text-decoration-none">#<?php echo $r['id']; ?></a>
                                    <?php if($has_media): ?>
                                        <i class="fas fa-camera text-info ms-1" title="<?php echo __('has_media'); ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></div>
                                    <div class="small text-white-75"><?php echo htmlspecialchars($r['phone']); ?></div>
                                </td>
                                <td>
                                    <div class="fw-medium text-primary"><?php echo $icon; ?> <?php echo htmlspecialchars($r['device_brand']); ?></div>
                                    <div class="small text-white-75"><?php echo htmlspecialchars($r['device_model']); ?></div>
                                </td>
                                <td>
                                    <div class="small problem-snippet"><?php echo htmlspecialchars(mb_strimwidth($r['problem_description'], 0, 56, "...")); ?></div>
                                    <span class="badge bg-transparent border border-secondary text-white-75 mt-2"><i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($r['tech_name'] ?? '---'); ?></span>
                                </td>
                                <td><?php echo getStatusBadge($r['status']); ?></td>
                                <td class="text-end pe-4"><strong><?php echo formatMoney($r['final_cost'] ?? $r['estimated_cost']); ?></strong></td>
                            </tr>
                            <?php endforeach; 
                            
                            if (!$found): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-white-75">
                                        <i class="fas fa-folder-open fa-2x mb-3 d-block opacity-25"></i>
                                        <?php echo __('not_found'); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('quick_actions'); ?></h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-2"></i> <?php echo __('new_order'); ?></button>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-user-plus me-2"></i> <?php echo __('customers'); ?></a>
                    <a href="inventory.php" class="btn btn-outline-info"><i class="fas fa-search me-2"></i> <?php echo __('check_stock'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Dashboard Right Column (Techs list if Admin) -->
        <?php if ($_SESSION['role'] == 'admin'): ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('online_techs'); ?></h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php
                    $all_techs = $pdo->query("SELECT name, last_seen FROM technicians WHERE is_active = 1 ORDER BY last_seen DESC")->fetchAll();
                    foreach ($all_techs as $tech):
                        $is_online = (strtotime($tech['last_seen'] ?? '0') > strtotime("-5 minutes"));
                    ?>
                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <div class="position-relative me-3">
                                <i class="fas fa-user-circle fa-2x text-white-75 opacity-50"></i>
                                <span class="position-absolute bottom-0 end-0 p-1 <?php echo $is_online ? 'bg-success' : 'bg-secondary'; ?> border border-light rounded-circle"></span>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($tech['name']); ?></div>
                                <small class="text-white-75">
                                    <?php echo $is_online ? __('tech_online') : __('tech_last_seen') . ': ' . ($tech['last_seen'] ? date('H:i, d.m', strtotime($tech['last_seen'])) : __('never')); ?>
                                </small>
                            </div>
                        </div>
                        <?php if ($is_online): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2"><?php echo __('tech_online'); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Order Modal -->
<div class="modal fade" id="newOrderModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content glass-card border-secondary text-white shadow-lg">
            <form action="api/add_order.php" method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <div class="modal-header bg-transparent border-secondary py-3">
                    <h5 class="modal-title"><?php echo __('new_order'); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- ═══ 1. КЛИЕНТ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user text-primary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('client'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <select name="customer_id" class="form-select select2-customer" style="width: 100%;" required>
                                    <option value=""><?php echo __('enter_name_or_phone'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-secondary w-100" id="toggleNewCustomerPanelBtn" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel" aria-expanded="false">
                                    <i class="fas fa-user-plus me-1"></i> <?php echo __('new_customer_btn'); ?>
                                </button>
                            </div>
                            <!-- Inline New Customer Panel (collapsible, inside the same modal) -->
                            <div class="col-12">
                                <div class="collapse" id="inlineNewCustomerPanel">
                                    <div class="card border-secondary bg-dark bg-opacity-25 mt-2">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6 class="mb-0 text-white"><i class="fas fa-user-plus me-2 text-primary"></i><?php echo __('add_customer'); ?></h6>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#inlineNewCustomerPanel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div id="newCustomerInlineForm">
                                                <div class="mb-3">
                                                    <div class="btn-group w-100" role="group">
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_private" value="private" checked>
                                                        <label class="btn btn-outline-primary" for="inline_type_private"><?php echo __('private_person'); ?></label>
                                                        <input type="radio" class="btn-check" name="customer_type" id="inline_type_company" value="company">
                                                        <label class="btn btn-outline-primary" for="inline_type_company"><?php echo __('company_entity'); ?></label>
                                                    </div>
                                                </div>
                                                <div id="inline_company_fields" class="d-none border border-secondary p-3 rounded bg-transparent mb-3">
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('ico'); ?></label>
                                                        <div class="input-group">
                                                            <input type="text" name="ico" id="inline_ico_input" class="form-control" placeholder="12345678">
                                                            <button class="btn btn-info text-white" type="button" id="inline_btn_fetch_ares">
                                                                <i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('company_name'); ?></label>
                                                        <input type="text" name="company_name" id="inline_ares_name" class="form-control">
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label"><?php echo __('dic'); ?></label>
                                                        <input type="text" name="dic" id="inline_ares_dic" class="form-control" placeholder="CZ12345678">
                                                    </div>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('name_col'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="first_name" id="inline_first_name" class="form-control">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label"><?php echo __('client'); ?> (<?php echo __('last_name_label'); ?>) <span class="text-danger">*</span></label>
                                                        <input type="text" name="last_name" id="inline_last_name" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('phone'); ?> <span class="text-danger">*</span></label>
                                                        <input type="tel" name="phone" id="inline_phone" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Email</label>
                                                        <input type="email" name="inline_email" class="form-control">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label"><?php echo __('address'); ?></label>
                                                        <textarea name="address" id="inline_address" class="form-control" rows="2"></textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="button" class="btn btn-success w-100" id="saveNewCustomerBtn">
                                                            <i class="fas fa-check me-2"></i><?php echo __('save'); ?>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 2. УСТРОЙСТВО ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-laptop text-info me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_device'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_type'); ?></label>
                                <select name="device_type" class="form-select" required>
                                    <option value="Phone">📱 <?php echo __('Phone'); ?></option>
                                    <option value="Notebook">💻 <?php echo __('Notebook'); ?></option>
                                    <option value="PC">🖥️ <?php echo __('PC'); ?></option>
                                    <option value="Tablet">📟 <?php echo __('Tablet'); ?></option>
                                    <option value="HDD">💾 <?php echo __('HDD'); ?></option>
                                    <option value="Other">❓ <?php echo __('Other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('warranty_type'); ?></label>
                                <select name="order_type" class="form-select" required>
                                    <option value="Non-Warranty">🛠 <?php echo __('warranty_no'); ?></option>
                                    <option value="Warranty">📜 <?php echo __('warranty_yes'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_brand'); ?></label>
                                <select name="device_brand" class="form-select select2-brand" style="width: 100%;" required>
                                    <option value=""><?php echo __('brand_placeholder'); ?></option>
                                    <?php foreach(getDeviceBrands() as $brand): ?>
                                        <option value="<?php echo $brand; ?>"><?php echo $brand; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('device_model'); ?></label>
                                <input type="text" name="device_model" class="form-control" placeholder="<?php echo __('model_placeholder'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial'); ?></label>
                                <input type="text" name="serial_number" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('serial_2'); ?></label>
                                <input type="text" name="serial_number_2" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label"><?php echo __('pin'); ?></label>
                                <input type="text" name="pin_code" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('appearance'); ?></label>
                                <input type="text" name="appearance" class="form-control">
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 3. ПРОБЛЕМА ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_problem'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label"><?php echo __('priority'); ?></label>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="priority" value="High" id="priorityHighDashboard">
                                    <label class="form-check-label" for="priorityHighDashboard"><?php echo __('high'); ?></label>
                                </div>
                            </div>
                            <?php if (!empty($order_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_note_templates) ? '4' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates'); ?></label>
                                <select class="form-select order-template-select" data-target="problem_description">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($order_note_templates)): ?>
                            <div class="col-md-<?php echo !empty($order_templates) ? '5' : '9'; ?>">
                                <label class="form-label"><?php echo __('templates_notes'); ?></label>
                                <select class="form-select order-template-select" data-target="technician_notes">
                                    <option value=""><?php echo __('template_select'); ?></option>
                                    <?php foreach ($order_note_templates as $tpl): ?>
                                        <option value="<?php echo e($tpl); ?>"><?php echo e($tpl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('problem'); ?></label>
                                <textarea name="problem_description" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo __('notes'); ?> <?php echo __('comment_suffix'); ?></label>
                                <textarea name="technician_notes" class="form-control" rows="2" placeholder="<?php echo __('notes_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 4. ФИНАНСЫ ═══ -->
                    <div class="mb-2">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-coins text-success me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_financial'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('cost_est'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="estimated_cost" class="form-control" step="0.01">
                                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="border-secondary my-3 opacity-50">

                    <!-- ═══ 5. ИСПОЛНИТЕЛЬ ═══ -->
                    <div class="mb-0">
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas fa-user-cog text-secondary me-2"></i>
                            <span class="fw-semibold small text-uppercase"><?php echo __('section_execution'); ?></span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('technician'); ?></label>
                                <select name="technician_id" class="form-select">
                                    <option value="">-- <?php echo __('technician'); ?> --</option>
                                    <?php foreach ($techs_list as $t): ?>
                                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (($_SESSION['role'] ?? '') !== 'admin' && $t['id'] == ($_SESSION['tech_id'] ?? 0)) ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo __('media_files'); ?></label>
                                <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                                <div class="form-text"><?php echo __('upload_multiple_hint'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-transparent border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>



<script>
$(document).ready(function() {
    let currentCustomerSearch = '';
    function escapeHtml(text) {
        return $('<div>').text(text).html();
    }
    function highlightMatch(text, term) {
        if (!term) return escapeHtml(text);
        const safe = escapeHtml(text);
        const re = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig');
        return safe.replace(re, '<span class="match">$1</span>');
    }

    $('.select2-customer').select2({
        dropdownParent: $('#newOrderModal'),
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            url: 'api/search_customers.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                currentCustomerSearch = params.term || '';
                return { q: params.term, page: params.page || 1 };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.results, pagination: { more: data.pagination.more } };
            }
        },
        templateResult: function(item) {
            if (item.loading) return item.text;
            const name = item.name || item.text || '';
            const phone = item.phone || '';
            const title = highlightMatch(name, currentCustomerSearch);
            const meta = phone ? '<span class="meta">' + highlightMatch(phone, currentCustomerSearch) + '</span>' : '';
            return $('<div class="customer-option"><div>' + title + '</div>' + meta + '</div>');
        },
        templateSelection: function(item) {
            return item.text || item.name || '';
        },
        escapeMarkup: function(markup) { return markup; }
    });

    $('.select2-brand').select2({
        dropdownParent: $('#newOrderModal'),
        placeholder: "<?php echo __('brand'); ?>",
        tags: true
    });

    $('.order-template-select').on('change', function() {
        const value = $(this).val();
        if (!value) return;
        const targetName = $(this).data('target');
        const $area = $(this).closest('form').find('textarea[name="' + targetName + '"]');
        if (!$area.length) return;
        const current = $area.val().trim();
        $area.val(current ? (current + "\n" + value) : value).trigger('input');
        $(this).val('');
    });

    // Inline New Customer: company/private toggle
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#inline_company_fields').removeClass('d-none');
            $('#inline_first_name').val('Firma');
            $('#inline_last_name').val('');
        } else {
            $('#inline_company_fields').addClass('d-none');
            $('#inline_first_name').val('');
            $('#inline_last_name').val('');
        }
    });

    // Inline New Customer: ARES fetch
    $('#inline_btn_fetch_ares').on('click', function() {
        const ico = $('#inline_ico_input').val().trim();
        if (!ico) return showAlert('<?php echo __('enter_ico'); ?>');
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search me-1"></i> <?php echo __('fetch_ares'); ?>');
                if (data && data.obchodniJmeno) {
                    $('#inline_ares_name').val(data.obchodniJmeno);
                    $('#inline_last_name').val(data.obchodniJmeno);
                    $('#inline_first_name').val('Firma');
                    
                    if (data.dic) {
                        $('#inline_ares_dic').val(data.dic);
                    }

                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#inline_address').val(addr.trim());
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

    // Inline New Customer: AJAX submit and bind to New Order select
    $('#saveNewCustomerBtn').on('click', function() {
        const $panel = $('#newCustomerInlineForm');
        const firstName = $('#inline_first_name').val().trim();
        const lastName = $('#inline_last_name').val().trim();
        const phone = $('#inline_phone').val().trim();
        
        if (!firstName || !lastName || !phone) {
            showAlert('<?php echo __('fill_required_fields'); ?>');
            return;
        }
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> <?php echo __('saving'); ?>...');

        const formData = {
            first_name: firstName,
            last_name: lastName,
            phone: phone,
            email: $panel.find('input[name="inline_email"]').val() || '',
            address: $('#inline_address').val() || '',
            customer_type: $panel.find('input[name="customer_type"]:checked').val() || 'private',
            ico: $('#inline_ico_input').val() || '',
            company_name: $('#inline_ares_name').val() || '',
            dic: $('#inline_ares_dic').val() || '',
            csrf_token: $('input[name="csrf_token"]').first().val()
        };

        $.post('api/add_customer.php', formData, function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            if (res.success) {
                const id = res.id;
                const label = (lastName + ' ' + firstName).trim() + (phone ? ' (' + phone + ')' : '');
                const $select = $('.select2-customer');
                if ($select.length) {
                    const newOption = new Option(label, id, true, true);
                    $select.append(newOption).trigger('change');
                }
                // Reset inline form fields
                $('#inline_first_name, #inline_last_name, #inline_phone, #inline_ares_name, #inline_ares_dic, #inline_ico_input').val('');
                $panel.find('input[name="inline_email"]').val('');
                $('#inline_address').val('');
                $('#inline_company_fields').addClass('d-none');
                $panel.find('#inline_type_private').prop('checked', true);
                // Collapse the panel
                const collapseEl = document.getElementById('inlineNewCustomerPanel');
                const bsCollapse = bootstrap.Collapse.getInstance(collapseEl);
                if (bsCollapse) bsCollapse.hide();
            } else {
                showAlert(res.message || '<?php echo __('add_client_error'); ?>');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="fas fa-check me-2"></i><?php echo __('save'); ?>');
            showAlert('<?php echo __('network_error_client'); ?>');
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

