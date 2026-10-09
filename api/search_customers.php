<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'auth' => true,
    'post' => false,
    'csrf' => false,
    'rate' => ['action' => 'search_customers', 'max' => 60, 'window' => 60],
    'json' => true,
]);

$term = trim($_GET['q'] ?? '');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$params = [];
$where_parts = [];
$order_by = 'created_at DESC, id DESC';

if ($term !== '') {
    $like = '%' . $term . '%';
    $exact_id = preg_match('/^\d+$/', $term) ? (int)$term : null;
    $search_sql = "(first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR company LIKE ? OR CONCAT_WS(' ', first_name, last_name) LIKE ? OR CONCAT_WS(' ', last_name, first_name) LIKE ?";
    $params = [$like, $like, $like, $like, $like, $like];
    if ($exact_id !== null) {
        $search_sql .= " OR id = ?";
        $params[] = $exact_id;
    }
    $search_sql .= ')';
    $where_parts[] = $search_sql;
    $order_by = 'last_name ASC, first_name ASC, id DESC';
}

// Technicians only search customers who have at least one order assigned to them.
if (isTechnicianScoped()) {
    $where_parts[] = 'EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = customers.id AND o.technician_id = ?)';
    $params[] = currentTechnicianId();
}

$where = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

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

    api_json_exit([
        'results' => $results,
        'pagination' => ['more' => (($offset + $per_page) < $total)]
    ]);
} catch (Exception $e) {
    error_log('search_customers: ' . $e->getMessage());
    api_json_exit(['results' => [], 'pagination' => ['more' => false]]);
}
