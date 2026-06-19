<?php
/**
 * API: Get Device Models (AJAX autocomplete)
 * Returns model names filtered by brand and search term.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
header('Content-Type: application/json');

checkApiRateLimit('device_models', 60, 60);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['results' => []]);
    exit;
}

$brand = trim($_GET['brand'] ?? '');
$term  = trim($_GET['term'] ?? ($_GET['q'] ?? ''));

try {
    $models = getDeviceModels($brand !== '' ? $brand : null, $term, 50);
    $results = array_map(static function ($row) {
        return [
            'id' => $row['model_name'],
            'text' => $row['model_name'],
            'model' => $row['model_name'],
        ];
    }, $models);

    // Select2 expects { results: [{id, text}, ...] }
    echo json_encode(['results' => $results]);
} catch (Exception $e) {
    echo json_encode(['results' => []]);
}
?>
