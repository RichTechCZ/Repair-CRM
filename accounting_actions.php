<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Access Check
if (!hasPermission('admin_access')) {
    die(json_encode(['success' => false, 'error' => 'Access denied']));
}

// Handle CSRF dynamically for ALL POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    die(json_encode(['success' => false, 'error' => 'Security token invalid.']));
}

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';
$valid_actions = ['save_invoice', 'get_invoice', 'delete_invoice', 'update_status', 'create_credit_note', 'export_pohoda', 'export_s3money', 'get_order_data'];
if (!in_array($action, $valid_actions)) {
    die(json_encode(['success' => false, 'error' => 'Invalid action']));
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
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'delete_invoice':
        try {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'update_status':
        try {
            require_once 'models/InvoiceManager.php';
            $manager = new InvoiceManager($pdo);
            $success = $manager->updateStatus((int)$_POST['id'], $_POST['status'], $_POST['payment_method'] ?? null);
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'create_credit_note':
        require_once 'models/InvoiceManager.php';
        $manager = new InvoiceManager($pdo);
        $result = $manager->createCreditNote((int)$_POST['id']);
        echo json_encode($result);
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
