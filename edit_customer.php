<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
// Page-level guard independent of header.php's permission map (defense in depth).
if (!hasPermission('edit_customers')) {
    header('Location: index.php');
    exit;
}
require_once 'includes/header.php';

$id = $_GET['id'] ?? null;
if (!$id) die(__('customer_id_missing'));

$id = (int)$id;

if (!currentUserCanViewCustomer($id)) {
    http_response_code(403);
    die(__('access_denied_msg'));
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) die(__('customer_not_found'));

$success = false;
$error = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        die(__('csrf_invalid'));
    }

    // Re-check after POST (session may have changed; prevent IDOR via form action).
    if (!currentUserCanViewCustomer($id)) {
        http_response_code(403);
        die(__('access_denied_msg'));
    }

    $customer_type = $_POST['customer_type'] ?? 'private';
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];
    $address = $_POST['address'];
    $ico = $_POST['ico'] ?? '';
    $dic = $_POST['dic'] ?? '';
    $company = $_POST['company'] ?? '';

    try {
        $update = $pdo->prepare("UPDATE customers SET customer_type = ?, first_name = ?, last_name = ?, phone = ?, phone_search = ?, email = ?, address = ?, ico = ?, dic = ?, company = ? WHERE id = ?");
        $update->execute([
            $customer_type,
            $first_name,
            $last_name,
            $phone,
            normalizePhoneForSearch($phone),
            $email,
            $address,
            $ico,
            $dic,
            $company,
            $id,
        ]);
        $success = __('customer_updated_success');
        // Refresh
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
    } catch (Exception $e) {
        $error = publicExceptionMessage($e);
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?php echo __('edit'); ?> <?php echo __('client'); ?></h1>
    <a href="customers.php" class="btn btn-outline-secondary"><?php echo __('back'); ?></a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="row g-3">
                <div class="col-12 mb-2">
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="customer_type" id="type_private" value="private" <?php echo ($customer['customer_type'] ?? 'private') == 'private' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="type_private"><?php echo __('private_person'); ?></label>

                        <input type="radio" class="btn-check" name="customer_type" id="type_company" value="company" <?php echo ($customer['customer_type'] ?? 'private') == 'company' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="type_company"><?php echo __('company_entity'); ?></label>
                    </div>
                </div>

                <div id="company_fields" class="<?php echo ($customer['customer_type'] ?? 'private') == 'company' ? '' : 'd-none'; ?> border p-3 rounded bg-dark bg-opacity-25 border-secondary mb-3">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('ico'); ?></label>
                            <div class="input-group">
                                <input type="text" name="ico" id="ico_input" class="form-control" value="<?php echo htmlspecialchars($customer['ico'] ?? ''); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="btn_fetch_ares" aria-label="<?php echo e(__('fetch_ares')); ?>">
                                    <i class="fas fa-search" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('dic'); ?></label>
                            <input type="text" name="dic" id="ares_dic" class="form-control" value="<?php echo htmlspecialchars($customer['dic'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?php echo __('company_name'); ?></label>
                            <input type="text" name="company" id="ares_name" class="form-control" value="<?php echo htmlspecialchars($customer['company'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?php echo __('client'); ?> (<?php echo __('client_first_name'); ?>)</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($customer['first_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('client'); ?> (<?php echo __('client_last_name'); ?>)</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($customer['last_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?php echo __('phone'); ?></label>
                    <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($customer['phone']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($customer['email']); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label"><?php echo __('address'); ?></label>
                    <textarea name="address" id="address_field" class="form-control" rows="2"><?php echo htmlspecialchars($customer['address']); ?></textarea>
                </div>
                <div class="col-12 mt-4 d-flex justify-content-between">
                    <button type="submit" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    <button type="button" class="btn btn-outline-danger" data-crm-action="delete-customer" data-crm-id="<?php echo (int)$id; ?>"><?php echo __('delete'); ?> <?php echo __('client'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php $crmJsFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE; ?>
<script nonce="<?php echo e(crmCspNonce()); ?>">
$(document).ready(function() {
    $('input[name="customer_type"]').on('change', function() {
        if ($(this).val() === 'company') {
            $('#company_fields').removeClass('d-none');
        } else {
            $('#company_fields').addClass('d-none');
        }
    });

    $('#btn_fetch_ares').on('click', function() {
        const ico = $('#ico_input').val().trim();
        if (!ico) return showAlert(<?php echo json_encode(__('enter_ico_prompt'), $crmJsFlags); ?>);
        
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>');

        $.ajax({
            url: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/${ico}`,
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                btn.prop('disabled', false).html('<i class="fas fa-search" aria-hidden="true"></i>');
                if (data && data.obchodniJmeno) {
                    $('#ares_name').val(data.obchodniJmeno);
                    if (data.dic) {
                        $('#ares_dic').val(data.dic);
                    }
                    if (data.sidlo) {
                        const s = data.sidlo;
                        const addr = `${s.nazevUlice || ''} ${s.cisloDomovni || ''}${s.cisloOrientacni ? '/' + s.cisloOrientacni : ''}, ${s.psc || ''} ${s.nazevObce || ''}`;
                        $('#address_field').val(addr.trim());
                    }
                } else {
                    showAlert(<?php echo json_encode(__('ares_data_not_found'), $crmJsFlags); ?>);
                }
            },
            error: function() {
                btn.prop('disabled', false).html('<i class="fas fa-search" aria-hidden="true"></i>');
                showAlert(<?php echo json_encode(__('ares_fetch_error'), $crmJsFlags); ?>);
            }
        });
    });
});

function deleteCustomer(id) {
    showConfirm(<?php echo json_encode(__('confirm_delete_customer'), $crmJsFlags); ?>, function() {
        $.post('api/delete_customer.php', {id: id, csrf_token: <?php echo json_encode($_SESSION['csrf_token'] ?? '', $crmJsFlags); ?>}, function(res) {
            if (res.success) {
                showAlert(<?php echo json_encode(__('customer_deleted'), $crmJsFlags); ?>);
                window.location.href = 'customers.php';
            } else {
                showAlert(<?php echo json_encode(__('error') . ': ', $crmJsFlags); ?> + res.message);
            }
        });
    }, undefined, 'danger');
}
</script>

<?php require_once 'includes/footer.php'; ?>
