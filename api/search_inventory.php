<?php
/**
 * Paged inventory search for the "add part" picker (Select2 AJAX format).
 * Any signed-in user may add parts to orders they can edit, so the catalogue is readable
 * to all authenticated users; purchase prices are not returned.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => false,
    'csrf' => false,
    'rate' => ['action' => 'search_inventory', 'max' => 120, 'window' => 60],
]);

const CRM_INVENTORY_SEARCH_PAGE = 30;

$term = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * CRM_INVENTORY_SEARCH_PAGE;

$where = '';
$params = [];
if ($term !== '') {
    // Prefix match on the indexed part name, infix on SKU fragments operators paste.
    $like = addcslashes(mb_substr($term, 0, 100), '\\%_');
    $where = 'WHERE part_name LIKE ? OR sku LIKE ?';
    $params = [$like . '%', '%' . $like . '%'];
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, part_name, sku, quantity FROM inventory $where
         ORDER BY part_name ASC, id ASC
         LIMIT " . (CRM_INVENTORY_SEARCH_PAGE + 1) . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > CRM_INVENTORY_SEARCH_PAGE;

    $results = [];
    foreach (array_slice($rows, 0, CRM_INVENTORY_SEARCH_PAGE) as $row) {
        $label = (string)$row['part_name'] . ((string)($row['sku'] ?? '') !== '' ? ' · ' . $row['sku'] : '');
        $results[] = [
            'id' => (int)$row['id'],
            'text' => $label . ' (' . __('in_stock') . ': ' . (int)$row['quantity'] . ')',
            'stock' => (int)$row['quantity'],
        ];
    }
    api_json_exit(['results' => $results, 'pagination' => ['more' => $more]]);
} catch (Throwable $e) {
    error_log('search_inventory: ' . $e->getMessage());
    api_json_exit(['results' => [], 'pagination' => ['more' => false]], 500);
}
