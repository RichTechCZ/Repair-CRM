<?php
/**
 * InvoiceManager Class
 * Handles all logic for Invoices, Items, Statuses and Conversions.
 */

class InvoiceManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get single invoice with items and customer data
     */
    public function getInvoice($id) {
        $stmt = $this->pdo->prepare("
            SELECT i.*, c.first_name, c.last_name, c.company, c.address, c.ico, c.dic 
            FROM invoices i 
            JOIN customers c ON i.customer_id = c.id 
            WHERE i.id = ?
        ");
        $stmt->execute([$id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
    if ($invoice) {
        $stmt = $this->pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map item_name to name for JS frontend consistency
        foreach ($items as &$item) {
            $item['name'] = $item['item_name'];
        }
        $invoice['items'] = $items;

        // Map status to readable format
        $invoice['status_badge'] = $this->getInvoiceStatusBadge($invoice['status']);

        // Check for linked order
        if (!empty($invoice['order_id'])) {
            $stmt_o = $this->pdo->prepare("SELECT device_brand, device_model FROM orders WHERE id = ?");
            $stmt_o->execute([$invoice['order_id']]);
            $ord = $stmt_o->fetch();
            if ($ord) {
                $invoice['order_display'] = "#" . $invoice['order_id'] . " (" . $ord['device_brand'] . " " . $ord['device_model'] . ")";
            }
        }
    }

    return $invoice;
}

private function getInvoiceStatusBadge($status) {
    switch ($status) {
        case 'draft': return 'Черновик';
        case 'issued': return 'Выставлен';
        case 'paid': return 'Оплачен';
        case 'overdue': return 'Просрочен';
        case 'cancelled': return 'Отменен';
        default: return $status;
    }
}

    public function saveInvoice($data) {
        if (!hasPermission('admin_access')) {
            return ['success' => false, 'error' => __('access_denied_simple')];
        }
        try {
            $this->pdo->beginTransaction();

            $id = !empty($data['id']) ? (int)$data['id'] : null;
            $isCreditNote = false;
            $existingPaymentDate = null;
            if ($id) {
                $invoiceLock = $this->pdo->prepare('SELECT invoice_type, payment_date FROM invoices WHERE id = ? FOR UPDATE');
                $invoiceLock->execute([$id]);
                $existingInvoice = $invoiceLock->fetch(PDO::FETCH_ASSOC);
                if ($existingInvoice === false) {
                    throw new RuntimeException('Invoice not found.');
                }
                $existingInvoiceType = $existingInvoice['invoice_type'];
                $existingPaymentDate = $existingInvoice['payment_date'] ?? null;
                $isCreditNote = $existingInvoiceType === 'credit_note';
            }
            $invoice_number = $data['invoice_number'] ?? '';
            $customer_id = (int)($data['customer_id'] ?? 0);
            $date_issue = !empty($data['date_issue']) ? $data['date_issue'] : date('Y-m-d');
            $date_tax = !empty($data['date_tax']) ? $data['date_tax'] : $date_issue;
            $date_due = !empty($data['date_due']) ? $data['date_due'] : date('Y-m-d', strtotime('+14 days'));
            
            $status = !empty($data['status']) ? $data['status'] : 'issued';
            $is_vat_payer = (isset($data['is_vat_payer']) && ($data['is_vat_payer'] == '1' || $data['is_vat_payer'] === true)) ? 1 : 0;
            $payment_method = !empty($data['payment_method']) ? $data['payment_method'] : 'bank_transfer';
            $payment_date = ($status == 'paid') ? ($existingPaymentDate ?: date('Y-m-d')) : null;
            
            $currency = !empty($data['currency']) ? $data['currency'] : 'Kč';
            $notes = $data['notes'] ?? '';
            $variable_symbol = !empty($data['variable_symbol']) ? $data['variable_symbol'] : $invoice_number;
            
            // Manual customer override fields
            $cust_name = !empty($data['cust_name']) ? $data['cust_name'] : null;
            $cust_address = !empty($data['cust_address']) ? $data['cust_address'] : null;
            $cust_ico = !empty($data['cust_ico']) ? $data['cust_ico'] : null;
            $cust_dic = !empty($data['cust_dic']) ? $data['cust_dic'] : null;

            $items = [];
            if (!empty($data['items'])) {
                $items = is_string($data['items']) ? json_decode($data['items'], true) : $data['items'];
            }
            if (!is_array($items)) $items = [];

            if ($invoice_number === '' || mb_strlen($invoice_number) > 64 || $customer_id <= 0) {
                throw new InvalidArgumentException('Invalid invoice number or customer.');
            }
            if (!in_array($status, ['draft', 'issued', 'paid', 'overdue', 'cancelled'], true)) {
                throw new InvalidArgumentException('Invalid invoice status.');
            }
            if (!in_array($payment_method, ['bank_transfer', 'cash', 'card'], true)) {
                throw new InvalidArgumentException('Invalid payment method.');
            }
            foreach ([$date_issue, $date_tax, $date_due] as $dateValue) {
                $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$dateValue);
                if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $dateValue) {
                    throw new InvalidArgumentException('Invalid invoice date.');
                }
            }
            if ($date_due < $date_issue || count($items) < 1 || count($items) > 100) {
                throw new InvalidArgumentException('Invalid invoice items or due date.');
            }
            foreach ($items as $item) {
                $itemName = trim((string)($item['name'] ?? ($item['item_name'] ?? '')));
                $qty = $item['quantity'] ?? ($item['qty'] ?? null);
                $price = $item['price'] ?? null;
                $vatRate = $item['vat_rate'] ?? ($item['vat'] ?? 0);
                if (
                    $itemName === '' ||
                    !is_numeric($qty) ||
                    !is_numeric($price) ||
                    !is_numeric($vatRate) ||
                    !is_finite((float)$qty) ||
                    !is_finite((float)$price) ||
                    !is_finite((float)$vatRate) ||
                    (float)$qty <= 0 ||
                    (!$isCreditNote && (float)$price < 0) ||
                    ($isCreditNote && (float)$price > 0) ||
                    (float)$vatRate < 0 ||
                    (float)$vatRate > 100
                ) {
                    throw new InvalidArgumentException('Invalid invoice item.');
                }
            }

            $customerLock = $this->pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
            $customerLock->execute([$customer_id]);
            if (!$customerLock->fetchColumn()) {
                throw new RuntimeException('Customer not found.');
            }

            $orderId = !empty($data['order_id']) ? (int)$data['order_id'] : null;
            if ($orderId !== null) {
                $orderLock = $this->pdo->prepare('SELECT customer_id FROM orders WHERE id = ? FOR UPDATE');
                $orderLock->execute([$orderId]);
                $orderCustomerId = $orderLock->fetchColumn();
                if ($orderCustomerId === false || (int)$orderCustomerId !== $customer_id) {
                    throw new InvalidArgumentException('Invoice order/customer mismatch.');
                }
            }

            // Calculate totals
            $totals = $this->calculateTotals($items, $is_vat_payer);
            $total_amount = (float)$totals['total'];
            $vat_amount = $is_vat_payer ? (float)$totals['vat'] : 0;

            if ($id) {
                $stmt = $this->pdo->prepare("
                    UPDATE invoices SET 
                        invoice_number = ?, variable_symbol = ?, customer_id = ?, order_id = ?, date_issue = ?, date_tax = ?, date_due = ?, 
                        total_amount = ?, vat_amount = ?, is_vat_payer = ?, status = ?, 
                        payment_method = ?, payment_date = ?, currency = ?, notes = ?,
                        cust_name_override = ?, cust_address_override = ?, cust_ico_override = ?, cust_dic_override = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $invoice_number, $variable_symbol, $customer_id, $orderId, $date_issue, $date_tax, $date_due,
                    $total_amount, $vat_amount, $is_vat_payer, $status, 
                    $payment_method, $payment_date, $currency, $notes,
                    $cust_name, $cust_address, $cust_ico, $cust_dic,
                    $id
                ]);
                $invoice_id = $id;
            } else {
                $stmt = $this->pdo->prepare("
                    INSERT INTO invoices (
                        invoice_number, variable_symbol, customer_id, order_id, date_issue, date_tax, date_due, 
                        total_amount, vat_amount, is_vat_payer, status, payment_method, payment_date, currency, notes,
                        cust_name_override, cust_address_override, cust_ico_override, cust_dic_override
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $invoice_number, $variable_symbol, $customer_id, $orderId, $date_issue, $date_tax, $date_due,
                    $total_amount, $vat_amount, $is_vat_payer, $status, $payment_method, $payment_date, $currency, $notes,
                    $cust_name, $cust_address, $cust_ico, $cust_dic
                ]);
                $invoice_id = $this->pdo->lastInsertId();
            }

            // Always refresh items
            $this->pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
            
            if (!empty($items)) {
                $stmt = $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($items as $item) {
                    $itemName = $item['name'] ?? ($item['item_name'] ?? '');
                    $qty = $item['quantity'] ?? ($item['qty'] ?? 1);
                    $unit = $item['unit'] ?? 'ks';
                    $price = $item['price'] ?? 0;
                    $vatRate = $item['vat_rate'] ?? ($item['vat'] ?? 0);

                    $stmt->execute([$invoice_id, $itemName, $qty, $unit, $price, $vatRate]);
                }
            }

            $this->pdo->commit();
            return ['success' => true, 'id' => $invoice_id];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('InvoiceManager::saveInvoice error: ' . $e->getMessage());
            return ['success' => false, 'error' => publicExceptionMessage($e)];
        }
    }

    /**
     * Update only status and related payment data
     */
    public function updateStatus($id, $status, $payment_method = null) {
        if (!in_array($status, ['draft', 'issued', 'paid', 'overdue', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid invoice status.');
        }
        if ($payment_method !== null && !in_array($payment_method, ['bank_transfer', 'cash', 'card'], true)) {
            throw new InvalidArgumentException('Invalid payment method.');
        }
        // Re-marking an already paid invoice must not move its original payment date.
        $sql = "UPDATE invoices SET status = ?, payment_date = " . ($status === 'paid' ? 'COALESCE(payment_date, ?)' : '?');
        $params = [$status, $status === 'paid' ? date('Y-m-d') : null];
        
        if ($payment_method) {
            $sql .= ", payment_method = ?";
            $params[] = $payment_method;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Create Credit Note (Opravný daňový doklad) from existing invoice
     */
    public function createCreditNote($invoice_id) {
        try {
            $this->pdo->beginTransaction();

            $lock = $this->pdo->prepare(
                'SELECT id, invoice_type, status FROM invoices WHERE id = ? FOR UPDATE'
            );
            $lock->execute([(int)$invoice_id]);
            $sourceInvoice = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$sourceInvoice) {
                throw new RuntimeException('Original invoice not found.');
            }
            if (($sourceInvoice['invoice_type'] ?? 'invoice') === 'credit_note') {
                throw new RuntimeException('A credit note cannot be created from another credit note.');
            }
            if (!in_array((string)$sourceInvoice['status'], ['issued', 'paid', 'overdue'], true)) {
                throw new RuntimeException('Only an issued invoice can be credited.');
            }

            $existingCredit = $this->pdo->prepare(
                "SELECT id FROM invoices
                 WHERE parent_id = ? AND invoice_type = 'credit_note' AND status != 'cancelled'
                 LIMIT 1 FOR UPDATE"
            );
            $existingCredit->execute([(int)$invoice_id]);
            if ($existingCredit->fetchColumn()) {
                throw new RuntimeException('A credit note already exists for this invoice.');
            }

            $original = $this->getInvoice((int)$invoice_id);
            if (!$original) {
                throw new RuntimeException('Original invoice not found.');
            }

            // Lock the numbering setting so concurrent credit notes cannot
            // calculate the same number.
            $defaultPrefix = 'ODD' . date('Y');
            $ensurePrefix = $this->pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value)
                 VALUES ('acc_credit_note_prefix', ?)
                 ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)"
            );
            $ensurePrefix->execute([$defaultPrefix]);
            $prefixStmt = $this->pdo->prepare(
                "SELECT setting_value FROM system_settings
                 WHERE setting_key = 'acc_credit_note_prefix' FOR UPDATE"
            );
            $prefixStmt->execute();
            $prefix = (string)($prefixStmt->fetchColumn() ?: $defaultPrefix);

            $numberStmt = $this->pdo->prepare(
                'SELECT invoice_number FROM invoices WHERE invoice_number LIKE ?'
            );
            $numberStmt->execute([addcslashes($prefix, '\\%_') . '%']);
            $highestSequence = 0;
            foreach ($numberStmt->fetchAll(PDO::FETCH_COLUMN) as $existingNumber) {
                $existingNumber = (string)$existingNumber;
                if (!str_starts_with($existingNumber, $prefix)) {
                    continue;
                }
                $suffix = substr($existingNumber, strlen($prefix));
                if ($suffix !== '' && ctype_digit($suffix)) {
                    $highestSequence = max($highestSequence, (int)$suffix);
                }
            }
            $new_number = $prefix . str_pad($highestSequence + 1, 4, '0', STR_PAD_LEFT);

            $stmt = $this->pdo->prepare("
                INSERT INTO invoices (
                    invoice_number, customer_id, order_id, date_issue, date_tax, date_due,
                    total_amount, vat_amount, is_vat_payer, status, payment_method, currency, 
                    parent_id, invoice_type, notes,
                    cust_name_override, cust_address_override, cust_ico_override, cust_dic_override
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, 'credit_note', ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $new_number,
                $original['customer_id'],
                $original['order_id'] ?: null,
                date('Y-m-d'),
                date('Y-m-d'),
                date('Y-m-d'),
                -abs((float)$original['total_amount']),
                -abs((float)$original['vat_amount']),
                $original['is_vat_payer'],
                $original['payment_method'], $original['currency'],
                $original['id'], "Opravný k faktuře " . $original['invoice_number'],
                $original['cust_name_override'], $original['cust_address_override'], 
                $original['cust_ico_override'], $original['cust_dic_override']
            ]);
            
            $new_id = $this->pdo->lastInsertId();

            // Copy items
            $stmt = $this->pdo->prepare("INSERT INTO invoice_items (invoice_id, item_name, quantity, unit, price, vat_rate) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($original['items'] as $item) {
                $stmt->execute([
                    $new_id, 
                    $item['item_name'], 
                    abs((float)$item['quantity']),
                    $item['unit'], 
                    -abs((float)$item['price']),
                    $item['vat_rate']
                ]);
            }

            $this->pdo->commit();
            return ['success' => true, 'id' => $new_id];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('InvoiceManager::createCreditNote error: ' . $e->getMessage());
            return ['success' => false, 'error' => publicExceptionMessage($e)];
        }
    }

    /**
     * Helper to calculate totals
     */
    private function calculateTotals($items, $is_vat_payer) {
        $subtotal = 0;
        $vat_total = 0;
        
        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? ($item['qty'] ?? 0));
            $price = (float)($item['price'] ?? 0);
            $vatRate = (float)($item['vat_rate'] ?? ($item['vat'] ?? 0));

            $line_sub = $price * $qty;
            $subtotal += $line_sub;
            
            if ($is_vat_payer) {
                $vat_total += $line_sub * ($vatRate / 100);
            }
        }
        
        return [
            'subtotal' => $subtotal,
            'vat' => $vat_total,
            'total' => $subtotal + $vat_total
        ];
    }
}
