<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
api_bootstrap([
    'post' => true,
    'csrf' => true,
    'rate' => 'test_tech_tg',
]);
$tech_id = $_POST['id'] ?? null;
if (!$tech_id) {
    echo json_encode(['success' => false, 'message' => 'No technician ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT name, telegram_id FROM technicians WHERE id = ?");
    $stmt->execute([$tech_id]);
    $tech = $stmt->fetch();

    if ($tech && $tech['telegram_id']) {
        if (!is_numeric($tech['telegram_id'])) {
            throw new Exception(__('tg_id_must_be_number'));
        }

        $msg = sprintf(__('tg_test_msg'), $tech['name']);
        $res = sendTelegramNotification($tech['telegram_id'], $msg);
        if ($res) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception(__('tg_send_error'));
        }
    } else {
        throw new Exception(__('tg_id_missing'));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => publicExceptionMessage($e)]);
}
?>
