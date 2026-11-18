<?php
/**
 * Update a customer's profile details (used by the inline customer card editor).
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'edit_customers',
    'rate' => 'update_customer',
    'json' => true,
]);

$customer_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$customer_id || $customer_id <= 0) {
    api_json_exit(['success' => false, 'message' => __('customer_id_missing')], 400);
}

if (!currentUserCanViewCustomer($customer_id)) {
    api_json_exit(['success' => false, 'message' => __('access_denied_msg')], 403);
}

$customer_type = trim((string)($_POST['customer_type'] ?? 'private'));
$first_name = trim((string)($_POST['first_name'] ?? ''));
$last_name = trim((string)($_POST['last_name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$ico = trim((string)($_POST['ico'] ?? ''));
$dic = trim((string)($_POST['dic'] ?? ''));
$company = trim((string)($_POST['company'] ?? $_POST['company_name'] ?? ''));

$validationFailed = $first_name === '' || $last_name === '' || $phone === ''
    || !in_array($customer_type, ['private', 'company'], true)
    || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
    || mb_strlen($first_name) > 50 || mb_strlen($last_name) > 50
    || mb_strlen($phone) > 20 || mb_strlen($email) > 100
    || mb_strlen($ico) > 20 || mb_strlen($dic) > 20 || mb_strlen($company) > 100
    || mb_strlen($address) > 1000;

if ($validationFailed) {
    api_json_exit(['success' => false, 'message' => __('missing_data')], 422);
}

try {
    $existsStmt = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
    $existsStmt->execute([$customer_id]);
    if (!$existsStmt->fetchColumn()) {
        api_json_exit(['success' => false, 'message' => __('customer_not_found')], 404);
    }

    $hasPhoneSearch = tableColumnExists('customers', 'phone_search');
    if ($hasPhoneSearch) {
        $stmt = $pdo->prepare(
            'UPDATE customers
             SET customer_type = ?, first_name = ?, last_name = ?, phone = ?, phone_search = ?,
                 email = ?, address = ?, ico = ?, dic = ?, company = ?
             WHERE id = ?'
        );
        $stmt->execute([
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
            $customer_id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE customers
             SET customer_type = ?, first_name = ?, last_name = ?, phone = ?,
                 email = ?, address = ?, ico = ?, dic = ?, company = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $customer_type,
            $first_name,
            $last_name,
            $phone,
            $email,
            $address,
            $ico,
            $dic,
            $company,
            $customer_id,
        ]);
    }

    $freshStmt = $pdo->prepare(
        'SELECT id, customer_type, first_name, last_name, ico, dic, company, phone, email, address, created_at
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $freshStmt->execute([$customer_id]);
    $row = $freshStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        api_json_exit(['success' => false, 'message' => __('customer_not_found')], 404);
    }

    $phoneClean = preg_replace('/[^0-9+]/', '', (string)$row['phone']);

    api_json_exit([
        'success' => true,
        'message' => __('customer_updated_success'),
        'customer' => [
            'id' => (int)$row['id'],
            'customer_type' => ($row['customer_type'] ?? 'private') === 'company' ? 'company' : 'private',
            'first_name' => (string)$row['first_name'],
            'last_name' => (string)$row['last_name'],
            'company' => (string)($row['company'] ?? ''),
            'ico' => (string)($row['ico'] ?? ''),
            'dic' => (string)($row['dic'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'phone_clean' => is_string($phoneClean) ? $phoneClean : '',
            'email' => (string)($row['email'] ?? ''),
            'address' => (string)($row['address'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'display_name' => crmCustomerDisplayName($row),
            'contact_name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
        ],
    ]);
} catch (Throwable $e) {
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)], 500);
}
