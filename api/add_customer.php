<?php
/**
 * Create a customer (form redirect or JSON for the inline new-order panel).
 *
 * JSON responses always go through api_json_exit so jQuery dataType:json
 * never treats a successful write as a "network error" due to buffer noise.
 */
ob_start();
require_once __DIR__ . '/../includes/api_bootstrap.php';

$requestFormat = strtolower(trim((string)($_POST['response_format'] ?? '')));
$acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || $requestFormat === 'json'
    || $acceptsJson;

api_bootstrap([
    'post' => true,
    'csrf' => true,
    'permission' => 'edit_customers',
    'rate' => 'add_customer',
    'json' => $isAjax,
    'fail' => static function (string $message, int $status) use ($isAjax): void {
        if ($isAjax) {
            api_json_exit(['success' => false, 'message' => $message], $status);
        }
        if ($status === 401) {
            header('Location: ../login.php');
            exit;
        }
        http_response_code($status);
        die($message);
    },
]);

$customer_type = $_POST['customer_type'] ?? 'private';
$first_name = trim((string)($_POST['first_name'] ?? ''));
$last_name = trim((string)($_POST['last_name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$ico = trim((string)($_POST['ico'] ?? ''));
$dic = trim((string)($_POST['dic'] ?? ''));
$company_name = trim((string)($_POST['company_name'] ?? ''));

if ($first_name === '' || $last_name === '' || $phone === '') {
    if ($isAjax) {
        api_json_exit(['success' => false, 'message' => __('fill_required_fields')]);
    }
    die(__('fill_required_fields') . " <a href='../customers.php'>" . __('back') . '</a>');
}

try {
    $phoneSearch = normalizePhoneForSearch($phone);
    $hasPhoneSearch = tableColumnExists('customers', 'phone_search');

    if ($hasPhoneSearch) {
        $stmt = $pdo->prepare(
            'INSERT INTO customers (customer_type, first_name, last_name, phone, phone_search, email, address, ico, dic, company)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $customer_type,
            $first_name,
            $last_name,
            $phone,
            $phoneSearch,
            $email,
            $address,
            $ico,
            $dic,
            $company_name,
        ]);
    } else {
        // Pre-migration production schema: write the customer without phone_search.
        $stmt = $pdo->prepare(
            'INSERT INTO customers (customer_type, first_name, last_name, phone, email, address, ico, dic, company)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $company_name,
        ]);
    }

    $id = (int)$pdo->lastInsertId();
    if ($id <= 0) {
        throw new RuntimeException(__('add_client_error'));
    }
    grantCustomerForOrderCreation($id);

    if ($isAjax) {
        api_json_exit(['success' => true, 'id' => $id]);
    }

    header('Location: ../customers.php?success=1');
    exit;
} catch (Throwable $e) {
    $safe = publicExceptionMessage($e);
    if ($isAjax) {
        api_json_exit(['success' => false, 'message' => $safe]);
    }
    die($safe . " <a href='../customers.php'>" . __('back') . '</a>');
}
