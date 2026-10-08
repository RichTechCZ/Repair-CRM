<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
header('Content-Type: application/json');

// Access Check
if (!hasPermission('admin_access')) {
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Handle CSRF dynamically for ALL POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(json_encode(['success' => false, 'error' => 'Security token invalid.']));
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$valid_actions = ['save_invoice', 'get_invoice', 'delete_invoice', 'update_status', 'create_credit_note', 'export_pohoda', 'export_s3money', 'get_order_data'];
if (!in_array($action, $valid_actions, true)) {
    die(json_encode(['success' => false, 'error' => 'Invalid action']));
}

$read_actions = ['get_invoice', 'get_order_data'];
$expected_method = in_array($action, $read_actions, true) ? 'GET' : 'POST';
if ($_SERVER['REQUEST_METHOD'] !== $expected_method) {
    http_response_code(405);
    header('Allow: ' . $expected_method);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

switch ($action) {
    case 'save_invoice':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            
            // Allow JS to send order_id via the from_order_id field
            if (empty($_POST['order_id']) && !empty($_POST['from_order_id'])) {
                $_POST['order_id'] = $_POST['from_order_id'];
            }

            $result = $manager->saveInvoice($_POST);
            echo json_encode($result);
        } catch (Throwable $e) {
            error_log('accounting save_invoice error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => publicExceptionMessage($e)]);
        }
        break;

    case 'get_invoice':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $invoice = $manager->getInvoice((int)$_GET['id']);
            
            if ($invoice) {
                echo json_encode(['success' => true, 'data' => $invoice]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invoice not found']);
            }
        } catch (Throwable $e) {
            error_log('accounting get_invoice error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => publicExceptionMessage($e)]);
        }
        break;

    case 'delete_invoice':
        try {
            $pdo->beginTransaction();
            $id = (int)$_POST['id'];
            if ($id <= 0) {
                throw new InvalidArgumentException('Invalid invoice ID.');
            }
            $lock = $pdo->prepare('SELECT id, status, myinvoice_invoice_id FROM invoices WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $invoiceRow = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$invoiceRow) {
                throw new RuntimeException('Invoice not found.');
            }
            // Issued/paid invoices are accounting records: they are corrected with a credit note, never deleted.
            if (!in_array((string)$invoiceRow['status'], ['draft', 'cancelled'], true) || !empty($invoiceRow['myinvoice_invoice_id'])) {
                throw new RuntimeException('Only a draft or cancelled invoice that was not sent to MyInvoice can be deleted. Use a credit note for issued invoices.');
            }
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('accounting delete_invoice error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => publicExceptionMessage($e)]);
        }
        break;

    case 'update_status':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $success = $manager->updateStatus((int)$_POST['id'], $_POST['status'], $_POST['payment_method'] ?? null);
            echo json_encode(['success' => $success]);
        } catch (Throwable $e) {
            error_log('accounting update_status error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => publicExceptionMessage($e)]);
        }
        break;

    case 'create_credit_note':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $result = $manager->createCreditNote((int)$_POST['id']);
            echo json_encode($result);
        } catch (Throwable $e) {
            error_log('accounting create_credit_note error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => publicExceptionMessage($e)]);
        }
        break;

    case 'export_pohoda':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
            break;
        }
        // Implementation for Pohoda XML
        require_once 'export_utils.php';
        $exporter = new AccountingExporter($pdo);
        $file = $exporter->exportToPohoda($id);
        echo json_encode(['success' => true, 'file' => $file]);
        break;

    case 'export_s3money':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
            break;
        }
        // Implementation for S3 Money CSV
        require_once 'export_utils.php';
        $exporter = new AccountingExporter($pdo);
        $file = $exporter->exportToS3Money($id);
        echo json_encode(['success' => true, 'file' => $file]);
        break;

    case 'get_order_data':
        $order_id = (int)$_GET['order_id'];
        $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.company, c.address, c.phone, c.email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            $is_vat_payer = (get_setting('acc_is_vat_payer', '0') == '1');
            $data = [
                'customer_id' => $order['customer_id'],
                'customer_display' => $order['company'] ?: ($order['first_name'] . ' ' . $order['last_name']),
                'total_amount' => $order['final_cost'] ?: $order['estimated_cost'],
                'is_vat_payer' => $is_vat_payer,
                'items' => [
                    ['name' => 'Oprava ' . $order['device_brand'] . ' ' . $order['device_model'], 'quantity' => 1, 'unit' => 'ks', 'price' => $order['final_cost'] ?: $order['estimated_cost'], 'vat_rate' => $is_vat_payer ? get_setting('acc_vat_rate', '21') : 0]
                ]
            ];
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
        }
        break;
}
