<?php
/**
 * api/get_imei_duplicates.php
 * Returns all orders that share the given serial_number / serial_number_2.
 *
 * GET ?sn=<serial_or_imei>
 * Response: { orders: [...] } | { error: "..." }
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'rate' => 'get_imei_duplicates',
    'fail' => static function (string $message, int $status): void {
        api_json_exit(['error' => $message, 'orders' => []], $status);
    },
]);

$sn = trim($_GET['sn'] ?? '');

if ($sn === '') {
    api_json_exit(['orders' => []]);
}

if (!isset($pdo)) {
    api_json_exit(['error' => 'DB unavailable'], 500);
}

try {
    $where = "WHERE (o.serial_number = ? OR o.serial_number_2 = ?)";
    $params = [$sn, $sn];

    if (!hasPermission('admin_access')) {
        if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['tech_id'])) {
            api_json_exit(['orders' => []]);
        }
        $where .= " AND o.technician_id = ?";
        $params[] = (int)$_SESSION['tech_id'];
    }

    $stmt = $pdo->prepare(
        "SELECT
             o.id,
             o.created_at,
             o.status,
             o.device_brand,
             o.device_model,
             o.serial_number,
             o.serial_number_2,
             c.first_name,
             c.last_name
         FROM orders o
         JOIN customers c ON o.customer_id = c.id
         $where
         ORDER BY o.created_at DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    api_json_exit(['orders' => $rows]);

} catch (PDOException $e) {
    error_log('get_imei_duplicates.php: ' . $e->getMessage());
    api_json_exit(['error' => 'DB error'], 500);
}
