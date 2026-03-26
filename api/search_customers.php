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
$use_recent = ($term === '');
if (!$use_recent) {
    $like = '%' . $term . '%';
    $where = "WHERE first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR company LIKE ?";
    $params = [$like, $like, $like, $like];
}

try {
    if ($use_recent) {
        $rows = $pdo->query(
            "SELECT c.id, c.first_name, c.last_name, c.phone, c.company
             FROM customers c
             JOIN (
                 SELECT customer_id, MAX(created_at) AS last_order
                 FROM orders
                 GROUP BY customer_id
             ) o ON o.customer_id = c.id
             ORDER BY o.last_order DESC
             LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);
        $total = count($rows);
    } else {
        $count_sql = "SELECT COUNT(*) FROM customers $where";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = "SELECT id, first_name, last_name, phone, company FROM customers $where ORDER BY last_name ASC LIMIT $per_page OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

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
        'pagination' => ['more' => (!$use_recent && ($offset + $per_page) < $total)]
    ]);
} catch (Exception $e) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
}
?>
