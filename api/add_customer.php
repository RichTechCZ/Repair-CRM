<?php
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
            if (ob_get_length()) {
                ob_clean();
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        if ($status === 401) {
            header('Location: ../login.php');
            exit;
        }
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

if (!$first_name || !$last_name || !$phone) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => __('fill_required_fields')]);
    } else {
        die(__('fill_required_fields') . " <a href='../customers.php'>" . __('back') . '</a>');
    }
    exit;
}

try {
    $stmt = $pdo->prepare('INSERT INTO customers (customer_type, first_name, last_name, phone, email, address, ico, dic, company) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$customer_type, $first_name, $last_name, $phone, $email, $address, $ico, $dic, $company_name]);
    $id = $pdo->lastInsertId();

    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $id]);
    } else {
        header('Location: ../customers.php?success=1');
    }
} catch (Exception $e) {
    $safe = publicExceptionMessage($e);
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $safe]);
    } else {
        die($safe . " <a href='../customers.php'>" . __('back') . '</a>');
    }
}
?>
