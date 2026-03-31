<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) die(__('order_id_missing'));

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) die(__('order_not_found'));

// Access Control for technicians
if ($_SESSION['role'] == 'technician' && !hasPermission('edit_orders') && $order['technician_id'] != $_SESSION['tech_id']) {
    die(__('no_edit_permission'));
}

// Fetch current customer for the remote customer selector
$customer_stmt = $pdo->prepare("SELECT id, first_name, last_name, phone, company FROM customers WHERE id = ?");
$customer_stmt->execute([$order['customer_id']]);
$current_customer = $customer_stmt->fetch();

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = __('csrf_token_invalid');
    } else {
        $customer_id = $_POST['customer_id'];
        $technician_id = $_POST['technician_id'];
        $device_type = $_POST['device_type'];
        $order_type = $_POST['order_type'];
        $device_brand = $_POST['device_brand'];
        $device_model = $_POST['device_model'];
        $serial_number = $_POST['serial_number'];
        $serial_number_2 = $_POST['serial_number_2'];
        $problem_description = $_POST['problem_description'];
        $technician_notes = $_POST['technician_notes'];
        $estimated_cost = $_POST['estimated_cost'];
        $status = $_POST['status'];

        try {
            // First get current status to see if it changed to Collected
            $stmt_curr = $pdo->prepare("SELECT status, shipping_date FROM orders WHERE id = ?");
            $stmt_curr->execute([$id]);
            $current_order = $stmt_curr->fetch();
            
            $shipping_date_sql = "";
            if ($status === 'Collected' && !$current_order['shipping_date']) {
                $shipping_date_sql = ", shipping_date = NOW()";
            }

            $update = $pdo->prepare("UPDATE orders SET 
                customer_id = ?, 
                technician_id = ?,
                device_type = ?, 
                order_type = ?,
                device_brand = ?,
                device_model = ?, 
                serial_number = ?, 
                serial_number_2 = ?, 
                problem_description = ?, 
                technician_notes = ?, 
                estimated_cost = ?, 
                status = ? 
                $shipping_date_sql
                WHERE id = ?");
            $update->execute([
                $customer_id, 
                $technician_id,
                $device_type, 
                $order_type,
                $device_brand,
                $device_model, 
                $serial_number, 
                $serial_number_2,
                $problem_description, 
                $technician_notes, 
                $estimated_cost, 
                $status, 
                $id
            ]);

            // Handle File Uploads during Edit
            if (isset($_FILES['files'])) {
                $files = $_FILES['files'];
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'video/quicktime', 'video/x-msvideo'];

                        foreach ($files['name'] as $key => $name) {
                            if ($files['error'][$key] == 0) {
                                $type = $files['type'][$key];
                                if (in_array($type, $allowed_types)) {
                                    $ext = pathinfo($name, PATHINFO_EXTENSION);
                                    $new_name = uniqid('order_' . $id . '_') . '.' . $ext;
                                    $path = $upload_dir . $new_name;
                                    
                                    if (move_uploaded_file($files['tmp_name'][$key], $path)) {
                                        $db_path = 'uploads/' . $new_name;
                                        $stmt_file = $pdo->prepare("INSERT INTO order_attachments (order_id, file_path, file_type, file_name) VALUES (?, ?, ?, ?)");
                                        $stmt_file->execute([$id, $db_path, $type, $name]);
                                    }
                                }
                            }
                        }
            }

            $success = __('order_updated_success');
            // Refresh order data
            $stmt->execute([$id]);
            $order = $stmt->fetch();
        } catch (Exception $e) {
            $error = __('update_error') . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center">
        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm me-3">
            <i class="fas fa-arrow-left"></i> <?php echo __('back'); ?>
        </a>
        <h2 class="mb-0"><?php echo __('edit_order_header'); ?><?php echo $order['id']; ?></h2>
    </div>
    <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-outline-secondary">
        <i class="fas fa-eye me-2"></i> <?php echo __('view'); ?>
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken()); ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-user me-2 text-primary"></i><?php echo __('client'); ?></label>
                    <select name="customer_id" class="form-select select2-customer-remote" required>
                        <?php
                        $edit_customer_name = trim(($current_customer['last_name'] ?? '') . ' ' . ($current_customer['first_name'] ?? ''));
                        $edit_customer_company = trim($current_customer['company'] ?? '');
                        if ($edit_customer_company !== '') {
                            $edit_customer_name = $edit_customer_company . ($edit_customer_name !== '' ? ' (' . $edit_customer_name . ')' : '');
                        }
                        $edit_customer_label = $edit_customer_name . (!empty($current_customer['phone']) ? ' (' . $current_customer['phone'] . ')' : '');
                        ?>
                        <option value="<?php echo (int)$order['customer_id']; ?>" selected>
                            <?php echo htmlspecialchars($edit_customer_label); ?>
                        </option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-user-cog me-2 text-info"></i><?php echo __('technician'); ?></label>
                    <select name="technician_id" class="form-select">
                        <option value="">-- <?php echo __('technician'); ?> --</option>
                        <?php 
                        $techs = $pdo->query("SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
                        foreach($techs as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php if($order['technician_id']==$t['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-tasks me-2 text-warning"></i><?php echo __('status'); ?></label>
                    <select name="status" class="form-select">
                        <?php if (($order['status'] ?? '') === 'Collected'): ?>
                            <option value="Collected" selected><?php echo __('collected'); ?></option>
                        <?php else: ?>
                            <option value="New" <?php if($order['status']=='New') echo 'selected'; ?>><?php echo __('new'); ?></option>
                            <option value="Pending Approval" <?php if($order['status']=='Pending Approval') echo 'selected'; ?>><?php echo __('pending_approval'); ?></option>
                            <option value="In Progress" <?php if($order['status']=='In Progress') echo 'selected'; ?>><?php echo __('in_progress'); ?></option>
                            <option value="Waiting for Parts" <?php if($order['status']=='Waiting for Parts') echo 'selected'; ?>><?php echo __('waiting_parts'); ?></option>
                            <option value="Completed" <?php if($order['status']=='Completed') echo 'selected'; ?>><?php echo __('completed'); ?></option>
                            <option value="Collected" <?php if($order['status']=='Collected') echo 'selected'; ?>><?php echo __('collected'); ?></option>
                            <option value="Cancelled" <?php if($order['status']=='Cancelled') echo 'selected'; ?>><?php echo __('cancelled'); ?></option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-laptop-medical me-2 text-secondary"></i><?php echo __('device_type'); ?></label>
                    <select name="device_type" class="form-select">
                        <option value="Phone" <?php if($order['device_type']=='Phone') echo 'selected'; ?>><?php echo __('phone_type'); ?></option>
                        <option value="Notebook" <?php if($order['device_type']=='Notebook') echo 'selected'; ?>><?php echo __('notebook_type'); ?></option>
                        <option value="Tablet" <?php if($order['device_type']=='Tablet') echo 'selected'; ?>><?php echo __('tablet_type'); ?></option>
                        <option value="Other" <?php if($order['device_type']=='Other') echo 'selected'; ?>><?php echo __('other_type'); ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-file-contract me-2 text-primary"></i><?php echo __('warranty_type'); ?></label>
                    <select name="order_type" class="form-select">
                        <option value="Non-Warranty" <?php if($order['order_type']=='Non-Warranty') echo 'selected'; ?>><?php echo __('warranty_no'); ?></option>
                        <option value="Warranty" <?php if($order['order_type']=='Warranty') echo 'selected'; ?>><?php echo __('warranty_yes'); ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-tag me-2 text-info"></i><?php echo __('device_brand'); ?></label>
                    <select name="device_brand" class="form-select select2-brand">
                        <?php foreach(getDeviceBrands() as $brand): ?>
                            <option value="<?php echo $brand; ?>" <?php echo ($brand == $order['device_brand']) ? 'selected' : ''; ?>><?php echo $brand; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-mobile-alt me-2 text-dark"></i><?php echo __('device_model'); ?></label>
                    <input type="text" name="device_model" class="form-control" value="<?php echo htmlspecialchars($order['device_model']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-barcode me-2 text-muted"></i><?php echo __('serial'); ?></label>
                    <input type="text" name="serial_number" class="form-control" value="<?php echo htmlspecialchars($order['serial_number']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-barcode me-2 text-muted"></i><?php echo __('serial_2'); ?></label>
                    <input type="text" name="serial_number_2" class="form-control" value="<?php echo htmlspecialchars($order['serial_number_2'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><i class="fas fa-money-bill-wave me-2 text-success"></i><?php echo __('cost_est'); ?></label>
                    <div class="input-group">
                        <input type="number" name="estimated_cost" class="form-control" step="0.01" value="<?php echo $order['estimated_cost']; ?>">
                        <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-exclamation-triangle me-2 text-danger"></i><?php echo __('problem'); ?></label>
                    <textarea name="problem_description" class="form-control" rows="3"><?php echo htmlspecialchars($order['problem_description']); ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-comment-alt me-2 text-info"></i><?php echo __('notes'); ?></label>
                    <textarea name="technician_notes" class="form-control" rows="3"><?php echo htmlspecialchars($order['technician_notes']); ?></textarea>
                </div>
                <div class="col-12 mt-3">
                    <label class="form-label"><i class="fas fa-images me-2 text-info"></i><?php echo __('media_files'); ?></label>
                    <div class="row g-2">
                        <?php 
                        $stmt_media = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
                        $stmt_media->execute([$id]);
                        $attachments = $stmt_media->fetchAll();
                        if (empty($attachments)): ?>
                            <div class="col-12 text-muted small"><?php echo __('no_media_files'); ?></div>
                        <?php else: ?>
                            <?php foreach ($attachments as $file): 
                                $isVideo = strpos($file['file_type'], 'video') !== false;
                            ?>
                                <div class="col-3 col-md-2" id="media-item-<?php echo $file['id']; ?>">
                                    <div class="card h-100 shadow-sm border position-relative">
                                        <button type="button" class="btn btn-danger btn-sm position-absolute top-0 end-0 p-1" style="z-index: 10; font-size: 0.6rem;" onclick="deleteMedia(<?php echo $file['id']; ?>)">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <div class="ratio ratio-1x1 bg-dark bg-opacity-25">
                                            <?php if ($isVideo): ?>
                                                <div class="d-flex align-items-center justify-content-center bg-dark"><i class="fas fa-video text-white"></i></div>
                                            <?php else: ?>
                                                <img src="<?php echo $file['file_path']; ?>" class="object-fit-cover" alt="Photo">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label"><i class="fas fa-upload me-2 text-primary"></i><?php echo __('add_media_files'); ?></label>
                    <input type="file" name="files[]" class="form-control" multiple accept="image/*,video/*">
                    <div class="form-text"><?php echo __('media_files_hint'); ?></div>
                </div>
                <div class="col-12 mt-4 d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    <button type="button" class="btn btn-outline-danger" onclick="deleteOrder(<?php echo $id; ?>)"><?php echo __('delete'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2-customer-remote').select2({
        placeholder: "<?php echo __('search_client_placeholder'); ?>",
        minimumInputLength: 0,
        ajax: {
            url: 'api/search_customers.php',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { q: params.term || '', page: params.page || 1 };
            },
            processResults: function(data, params) {
                params.page = params.page || 1;
                return { results: data.results, pagination: { more: !!(data.pagination && data.pagination.more) } };
            }
        },
        width: '100%'
    });

    $('.select2-brand').select2({
        placeholder: "<?php echo __('brand_placeholder'); ?>",
        tags: true,
        width: '100%'
    });
});

function deleteOrder(id) {
    showConfirm('<?php echo __('confirm_delete_order_full'); ?>', function() {
        $.post('api/delete_order.php', {
            id: id,
            csrf_token: $('meta[name="csrf-token"]').attr('content')
        }, function(res) {
            if (res.success) {
                showAlert('<?php echo __('order_deleted'); ?>');
                window.location.href = 'orders.php';
            } else {
                showAlert('<?php echo __('error'); ?>: ' + res.message);
            }
        });
    });
}

function deleteMedia(id) {
    const mediaNode = $('#media-item-' + id);
    const requestData = {
        id: id,
        csrf_token: $('meta[name="csrf-token"]').attr('content')
    };

    showConfirm('<?php echo __('confirm_delete_file'); ?>', function() {
        $.ajax({
            url: 'api/delete_media.php',
            type: 'POST',
            dataType: 'json',
            data: requestData,
            success: function(res) {
                if (res && res.success) {
                    mediaNode.fadeOut(180, function() {
                        $(this).remove();
                    });
                } else {
                    const message = (res && res.message) ? res.message : '<?php echo __('error'); ?>';
                    showAlert('<?php echo __('error'); ?>: ' + message);
                }
            },
            error: function(xhr) {
                const message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : '<?php echo __('error'); ?>';
                showAlert('<?php echo __('error'); ?>: ' + message);
            }
        });
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
