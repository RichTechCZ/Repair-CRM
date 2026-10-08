<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'models/OrderStatusService.php';

$sync_token = trim((string)(getenv('SYNC_TOKEN') ?: ''));
if ($sync_token === '') {
    http_response_code(503);
    echo "SYNC_TOKEN is not configured\n";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', php_sapi_name() === 'cli' ? '1' : '0');

if (php_sapi_name() !== 'cli') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo "Method not allowed\n";
        exit;
    }

    // Token must NOT travel in query string (logs, Referer, browser history).
    // Accept: X-Sync-Token header, Authorization: Bearer <token>, or POST field "token".
    $provided_token = '';
    $header_token = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
    if ($header_token !== '') {
        $provided_token = (string)$header_token;
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(\S+)/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $provided_token = $m[1];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(\S+)/i', (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $m)) {
        $provided_token = $m[1];
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['token'])) {
        $provided_token = (string)$_POST['token'];
    }

    if ($provided_token === '' || !hash_equals($sync_token, $provided_token)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}


if (!isset($pdo)) {
    die("PDO not initialized. Check config.php\n");
}

if (php_sapi_name() === 'cli') {
    echo "Working directory: " . getcwd() . "\n";
}

function sync_orders($data) {
    global $pdo;
    $updated = 0;
    foreach ($data as $item) {
        $id = intval($item['id']);
        if (!$id) continue;
        
        $zap = $item['zap'] ?? null; // d.m.Y or null
        $amountRaw = $item['amt'] ?? null;
        if (!is_numeric($amountRaw) || !is_finite((float)$amountRaw) || (float)$amountRaw < 0) {
            echo "Skipped Order #$id: invalid amount\n";
            continue;
        }
        $amt = (float)$amountRaw;
        
        $shipping_date = null;
        if ($zap && $zap !== '-') {
            $d = DateTime::createFromFormat('j.m.Y', $zap);
            if ($d) $shipping_date = $d->format('Y-m-d H:i:s');
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $local = $stmt->fetch();
            if (!$local) {
                $pdo->rollBack();
                continue;
            }

            $newStatus = $shipping_date ? getOrderStatusStorageValue('Issued') : $local['status'];
            OrderStatusService::assertIssuedRequirements(
                canonicalOrderStatus($newStatus),
                $amt,
                $local['shipping_method'] ?? null,
                true,
                $local['order_type'] ?? null
            );

            $needsUpdate =
                abs((float)$local['final_cost'] - $amt) > 0.01 ||
                $local['status'] !== $newStatus ||
                (
                    $shipping_date &&
                    (
                        !$local['shipping_date'] ||
                        abs(strtotime($local['shipping_date']) - strtotime($shipping_date)) > 86400
                    )
                );
            if (!$needsUpdate) {
                $pdo->rollBack();
                continue;
            }

            $pdo->prepare(
                'UPDATE orders
                 SET final_cost = ?, status = ?, shipping_date = COALESCE(?, shipping_date), updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([$amt, $newStatus, $shipping_date, $id]);

            $effects = OrderStatusService::applyInTransaction(
                $pdo,
                $id,
                $local['status'],
                $newStatus,
                $amt
            );
            $pdo->commit();
            $updated++;
            echo "Updated Order #$id\n";

            try {
                OrderStatusService::afterCommit(
                    $pdo,
                    $id,
                    $local['status'],
                    $newStatus,
                    $amt,
                    $effects['invoice_to_sync']
                );
            } catch (Throwable $sideEffectError) {
                error_log("sync_from_site Order #$id post-commit side effect failed: " . $sideEffectError->getMessage());
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("sync_from_site Order #$id failed: " . $e->getMessage());
            echo "Skipped Order #$id: validation or update failed\n";
        }
    }
    return $updated;
}

// Data will be passed via temporary file or similar
if (file_exists('temp_sync_data.json')) {
    $data = json_decode(file_get_contents('temp_sync_data.json'), true);
    if ($data) {
        $count = sync_orders($data);
        echo "Sync finished. Total updated: $count\n";
    }
}
