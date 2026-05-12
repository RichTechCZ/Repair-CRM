<?php
/**
 * api/get_imei_duplicates.php
 * Returns all orders that share the given serial_number / serial_number_2.
 *
 * GET ?sn=<serial_or_imei>
 * Response: { orders: [...] } | { error: "..." }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

ob_clean(); // discard any warnings/notices before JSON output
header('Content-Type: application/json; charset=utf-8');

// Auth check (same pattern as other API endpoints)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$sn = trim($_GET['sn'] ?? '');

if ($sn === '') {
    echo json_encode(['orders' => []]);
    exit;
}

if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['error' => 'DB unavailable']);
    exit;
}

try {
    $where = "WHERE (o.serial_number = ? OR o.serial_number_2 = ?)";
    $params = [$sn, $sn];

    if (!hasPermission('admin_access') && !hasPermission('view_all_orders')) {
        if (($_SESSION['role'] ?? '') !== 'technician' || empty($_SESSION['tech_id'])) {
            echo json_encode(['orders' => []], JSON_UNESCAPED_UNICODE);
            exit;
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

    echo json_encode(['orders' => $rows], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('get_imei_duplicates.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
