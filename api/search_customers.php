<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
    exit;
}

$term = trim($_GET['q'] ?? '');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$params = [];
$where = '';
$order_by = 'created_at DESC, id DESC';
if ($term !== '') {
    $like = '%' . $term . '%';
    $exact_id = preg_match('/^\d+$/', $term) ? $term : null;
    $where = "WHERE first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR company LIKE ? OR CONCAT_WS(' ', first_name, last_name) LIKE ? OR CONCAT_WS(' ', last_name, first_name) LIKE ?";
    $params = [$like, $like, $like, $like, $like, $like];
    if ($exact_id !== null) {
        $where .= " OR id = ?";
        $params[] = (int)$exact_id;
    }
    $order_by = 'last_name ASC, first_name ASC, id DESC';
}

try {
    $count_sql = "SELECT COUNT(*) FROM customers $where";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql = "SELECT id, first_name, last_name, phone, company FROM customers $where ORDER BY $order_by LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $r) {
        $name = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? ''));
        $company = trim($r['company'] ?? '');
        if ($company !== '') {
            $name = $company . ($name !== '' ? ' (' . $name . ')' : '');
        }
        $phone = $r['phone'] ?? '';
        $text = $name . ($phone !== '' ? ' (' . $phone . ')' : '');
        $results[] = [
            'id' => (int)$r['id'],
            'text' => $text,
            'name' => $name,
            'phone' => $phone
        ];
    }

    echo json_encode([
        'results' => $results,
        'pagination' => ['more' => (($offset + $per_page) < $total)]
    ]);
} catch (Exception $e) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
}
?>
