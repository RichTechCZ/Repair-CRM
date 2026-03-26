<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!isset($_SESSION['user_id']) || !hasPermission('edit_customers')) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    } else {
        header('Location: ../login.php');
    }
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    } else {
        die(__('csrf_token_invalid'));
    }
    exit;
}

$customer_type = $_POST['customer_type'] ?? 'private';
$first_name = $_POST['first_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$phone = $_POST['phone'] ?? '';
$email = $_POST['email'] ?? '';
$address = $_POST['address'] ?? '';
$ico = $_POST['ico'] ?? '';
$dic = $_POST['dic'] ?? '';
$company_name = $_POST['company_name'] ?? '';

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
    if ($isAjax) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        die(sprintf(__('db_error'), $e->getMessage()) . " <a href='../customers.php'>" . __('back') . '</a>');
    }
}
?>
