<?php
/**
 * API: Get Device Models (AJAX autocomplete)
 * Returns model names filtered by brand and search term.
 */
require_once __DIR__ . '/../includes/api_bootstrap.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'rate' => ['action' => 'device_models', 'max' => 60, 'window' => 60],
    'fail' => static function (string $message, int $status): void {
        api_json_exit(['results' => [], 'error' => $message], $status);
    },
]);

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
    api_json_exit(['results' => $results]);
} catch (Exception $e) {
    api_json_exit(['results' => []]);
}
