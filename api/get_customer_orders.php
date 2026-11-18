<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../includes/upload_security.php';
require_once __DIR__ . '/../models/OrderStatusService.php';

api_bootstrap([
    'post' => false,
    'csrf' => false,
    'rate' => 'get_customer_orders',
]);

$customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
if (!$customer_id || $customer_id <= 0) {
    api_json_exit(['success' => false, 'message' => 'No customer ID'], 400);
}

if (!currentUserCanViewCustomer($customer_id)) {
    api_json_exit(['success' => false, 'message' => __('access_denied_msg')], 403);
}

/**
 * `.status-pill--*` variant for a lifecycle status. Derived from getStatusBadge()
 * so the customer card can never drift from the canonical mapping.
 */
function customerOrderStatusVariant(string $status): string {
    if (preg_match('/status-pill--([a-z-]+)/', getStatusBadge($status), $match)) {
        return $match[1];
    }
    return 'closed';
}

function customerInvoiceStatusMeta(string $status): array {
    $badgeHtml = getInvoiceStatusBadge($status);
    $variant = 'closed';
    if (preg_match('/status-pill--([a-z-]+)/', $badgeHtml, $match)) {
        $variant = $match[1];
    }
    $labels = [
        'draft' => __('status_draft'),
        'issued' => __('status_invoice_issued'),
        'paid' => __('status_paid'),
        'overdue' => __('status_overdue'),
        'cancelled' => __('status_cancelled'),
    ];
    return [
        'variant' => $variant,
        'label' => $labels[$status] ?? $status,
    ];
}

try {
    $custStmt = $pdo->prepare(
        'SELECT id, customer_type, first_name, last_name, ico, dic, company, phone, email, address, created_at
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $custStmt->execute([$customer_id]);
    $customerRow = $custStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customerRow) {
        api_json_exit(['success' => false, 'message' => __('customer_not_found')], 404);
    }

    $isAdmin = hasPermission('admin_access');
    $techScoped = isTechnicianScoped();
    $techId = currentTechnicianId();
    $currency = (string)get_setting('currency', 'Kč');

    $cancelReasonCol = tableColumnExists('orders', 'cancellation_reason')
        ? 'o.cancellation_reason'
        : 'NULL AS cancellation_reason';

    // Never select pin_code in customer card payloads.
    // Technicians only see their own orders for this customer (no cross-tech leak).
    $orderSql =
        "SELECT o.id, o.customer_id, o.device_type, o.order_type, o.device_brand, o.device_model,
                o.serial_number, o.serial_number_2, o.appearance, o.priority,
                o.problem_description, o.technician_notes, {$cancelReasonCol},
                o.estimated_cost, o.final_cost, o.status, o.technician_id,
                o.created_at, o.updated_at, o.shipping_method, o.shipping_tracking, o.shipping_date,
                t.name AS tech_name
         FROM orders o
         LEFT JOIN technicians t ON t.id = o.technician_id
         WHERE o.customer_id = ?";
    $orderParams = [$customer_id];
    if ($techScoped) {
        $orderSql .= ' AND o.technician_id = ?';
        $orderParams[] = $techId;
    }
    $mode = $_GET['section'] ?? 'overview';
    if (!in_array($mode, ['overview', 'repairs', 'detail', 'invoices', 'devices'], true)) {
        api_json_exit(['success' => false], 400);
    }
    $offset = max(0, (int)filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT));
    $pageSize = 20;
    if ($mode === 'detail') {
        $orderSql .= ' AND o.id = ?';
        $orderParams[] = (int)filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);
    }
    if ($mode === 'repairs') {
        $categories = ['active' => ['Accepted','Diagnostics','Approval','In Repair','Ready'],
            'completed' => ['Ready','Issued'], 'cancelled' => ['Issued Without Repair','Repair Cancelled']];
        $category = (string)($_GET['category'] ?? '');
        if (isset($categories[$category])) {
            $orderSql .= ' AND ' . buildStatusInCondition('o.status', $categories[$category], $orderParams);
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $orderSql .= " AND (LOCATE(?, CONCAT_WS(' ', CONCAT('#',o.id),o.device_brand,o.device_model,o.device_type,o.serial_number,o.serial_number_2,o.problem_description,o.technician_notes,t.name,o.status)) > 0 OR EXISTS
                (SELECT 1 FROM order_items oi LEFT JOIN inventory i ON i.id=oi.inventory_id WHERE oi.order_id=o.id AND LOCATE(?,COALESCE(NULLIF(oi.part_name,''),i.part_name,''))>0)";
            array_push($orderParams, $q, $q);
            foreach (getAllStatuses() as $status) {
                if (mb_stripos(getStatusLabel($status), $q) !== false) {
                    $orderSql .= ' OR ' . buildStatusInCondition('o.status', [$status], $orderParams);
                }
            }
            foreach (crmDeviceTypes() as $deviceType) {
                if (mb_stripos(__($deviceType), $q) !== false) {
                    $orderSql .= ' OR o.device_type = ?';
                    $orderParams[] = $deviceType;
                }
            }
            $orderSql .= ')';
        }
    }
    $orderSql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT ' . ($pageSize + 1) . ' OFFSET ' . $offset;
    $pageRows = [];
    if (in_array($mode, ['overview', 'repairs', 'detail'], true)) {
        $stmt = $pdo->prepare($orderSql);
        $stmt->execute($orderParams);
        $pageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $hasMore = count($pageRows) > $pageSize;
    if ($hasMore) {
        array_pop($pageRows);
    }
    $orderIds = array_column($pageRows, 'id');
    $orderRows = $pageRows;
    if ($mode === 'devices') {
        // Group devices across the full scoped history only when this tab is requested.
        $summaryStmt = $pdo->prepare('SELECT id, status, order_type, device_type, device_brand, device_model,
            serial_number, serial_number_2, created_at, final_cost, estimated_cost FROM orders
            WHERE customer_id = ?' . ($techScoped ? ' AND technician_id = ?' : '') . ' ORDER BY created_at DESC,id DESC');
        $summaryStmt->execute($orderParams);
        $orderRows = $summaryStmt;
    }

    $itemsByOrder = [];
    $attachmentsByOrder = [];
    $statusDateByOrder = [];

    if ($mode === 'detail' && !empty($orderIds)) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));

        try {
            $itemStmt = $pdo->prepare(
                "SELECT oi.order_id, oi.quantity, oi.price,
                        COALESCE(NULLIF(TRIM(oi.part_name), ''), NULLIF(TRIM(i.part_name), ''), '—') AS part_label
                 FROM order_items oi
                 LEFT JOIN inventory i ON i.id = oi.inventory_id
                 WHERE oi.order_id IN ($ph)
                 ORDER BY oi.id ASC"
            );
            $itemStmt->execute($orderIds);
        } catch (PDOException $e) {
            $itemStmt = $pdo->prepare(
                "SELECT oi.order_id, oi.quantity, oi.price, i.part_name AS part_label
                 FROM order_items oi
                 JOIN inventory i ON i.id = oi.inventory_id
                 WHERE oi.order_id IN ($ph)
                 ORDER BY oi.id ASC"
            );
            $itemStmt->execute($orderIds);
        }

        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
            $oid = (int)$itemRow['order_id'];
            $itemsByOrder[$oid][] = [
                'name' => (string)($itemRow['part_label'] ?? '—'),
                'quantity' => max(1, (int)($itemRow['quantity'] ?? 1)),
                'price' => $itemRow['price'] !== null ? (float)$itemRow['price'] : null,
            ];
        }

        try {
            $attStmt = $pdo->prepare(
                "SELECT id, order_id, file_type, file_name, created_at
                 FROM order_attachments
                 WHERE order_id IN ($ph)
                 ORDER BY created_at DESC, id DESC"
            );
            $attStmt->execute($orderIds);
            foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $attRow) {
                $oid = (int)$attRow['order_id'];
                $mime = (string)($attRow['file_type'] ?? '');
                $attachmentsByOrder[$oid][] = [
                    'id' => (int)$attRow['id'],
                    'file_type' => $mime,
                    'file_name' => (string)($attRow['file_name'] ?? ''),
                    'url' => crmOrderAttachmentUrl((int)$attRow['id']),
                    'is_image' => str_starts_with($mime, 'image/'),
                    'is_video' => str_starts_with($mime, 'video/'),
                    'created_at' => (string)($attRow['created_at'] ?? ''),
                ];
            }
        } catch (PDOException $e) {
            $attachmentsByOrder = [];
        }

        try {
            $logStmt = $pdo->prepare(
                "SELECT order_id, new_status, changed_at
                 FROM order_status_log
                 WHERE order_id IN ($ph)
                 ORDER BY changed_at DESC, id DESC"
            );
            $logStmt->execute($orderIds);
            $orderStatusLookup = [];
            foreach ($orderRows as $r) {
                $orderStatusLookup[(int)$r['id']] = canonicalOrderStatus((string)($r['status'] ?? ''));
            }
            foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $logRow) {
                $oid = (int)$logRow['order_id'];
                if (isset($statusDateByOrder[$oid])) {
                    continue;
                }
                if (canonicalOrderStatus((string)($logRow['new_status'] ?? '')) === ($orderStatusLookup[$oid] ?? '')) {
                    $statusDateByOrder[$oid] = (string)$logRow['changed_at'];
                }
            }
        } catch (PDOException $e) {
            $statusDateByOrder = [];
        }
    }

    // Invoices belong to admin_access users only (same contract as print_invoice.php / accounting.php).
    $invoicesPayload = [];
    $latestOrderInvoiceMap = [];
    $financeAvailable = true;
    if ($isAdmin) {
        try {
            $invoiceCount = null;
            if ($mode === 'overview') {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE customer_id = ?');
                $countStmt->execute([$customer_id]);
                $invoiceCount = (int)$countStmt->fetchColumn();
            }
            $sql = 'SELECT id, invoice_number, order_id, invoice_type, status, date_issue, date_due, payment_date, total_amount, currency, created_at FROM invoices WHERE customer_id = ?';
            $params = [$customer_id];
            if ($mode !== 'invoices') {
                $sql .= " AND order_id IS NOT NULL AND status <> 'cancelled' AND COALESCE(invoice_type,'invoice') <> 'credit_note'";
                $sql .= ' AND order_id IN (' . implode(',', array_fill(0, max(1, count($orderIds)), '?')) . ')';
                array_push($params, ...($orderIds ?: [0]));
                $sql .= " AND id = (SELECT i.id FROM invoices i WHERE i.customer_id = invoices.customer_id AND i.order_id = invoices.order_id
                     AND i.status <> 'cancelled' AND COALESCE(i.invoice_type,'invoice') <> 'credit_note'
                     ORDER BY COALESCE(i.payment_date,i.date_issue,i.created_at) DESC,i.id DESC LIMIT 1)";
            }
            $sql .= ' ORDER BY COALESCE(payment_date,date_issue,created_at) DESC,id DESC';
            if ($mode === 'invoices') {
                $sql .= ' LIMIT ' . ($pageSize + 1) . ' OFFSET ' . $offset;
            }
            $invStmt = $pdo->prepare($sql);
            $invStmt->execute($params);
            foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
                $invStatus = (string)($inv['status'] ?? 'issued');
                $invType = (string)($inv['invoice_type'] ?? 'invoice');
                $invMeta = customerInvoiceStatusMeta($invStatus);
                $invOid = !empty($inv['order_id']) ? (int)$inv['order_id'] : null;

                $invPayload = [
                    'id' => (int)$inv['id'],
                    'invoice_number' => (string)$inv['invoice_number'],
                    'order_id' => $invOid,
                    'invoice_type' => $invType,
                    'status' => $invStatus,
                    'status_label' => $invMeta['label'],
                    'status_variant' => $invMeta['variant'],
                    'date_issue' => (string)($inv['date_issue'] ?? ''),
                    'date_due' => (string)($inv['date_due'] ?? ''),
                    'payment_date' => $inv['payment_date'] ? (string)$inv['payment_date'] : null,
                    'total_amount' => (float)$inv['total_amount'],
                    'currency' => (string)($inv['currency'] ?: $currency),
                ];
                if ($mode === 'invoices') {
                    $invoicesPayload[] = $invPayload;
                }

                if ($invOid !== null && $invType !== 'credit_note' && $invStatus !== 'cancelled' && !isset($latestOrderInvoiceMap[$invOid])) {
                    $latestOrderInvoiceMap[$invOid] = $invPayload;
                }
            }
        } catch (PDOException $e) {
            error_log("Customer invoice lookup failed: " . $e->getMessage());
            $financeAvailable = false;
            $invoicesPayload = [];
            $latestOrderInvoiceMap = [];
        }
    }

    if ($mode === 'invoices') {
        if (!$isAdmin) api_json_exit(['success' => false], 403);
        if (!$financeAvailable) api_json_exit(['success' => false, 'message' => __('invoice_data_unavailable')], 503);
        $more = count($invoicesPayload) > $pageSize;
        api_json_exit(['success' => true, 'invoices' => array_slice($invoicesPayload, 0, $pageSize), 'has_more' => $more]);
    }
    $activeStatuses = ['Accepted', 'Diagnostics', 'Approval', 'In Repair', 'Ready'];
    $cancelledStatuses = ['Issued Without Repair', 'Repair Cancelled'];

    $summary = [
        'total_orders' => 0,
        'active_orders' => 0,
        'ready_orders' => 0,
        'issued_orders' => 0,
        'cancelled_orders' => 0,
        'warranty_orders' => 0,
        'unique_devices' => 0,
        'total_spent' => 0.0,
        'active_estimate_total' => 0.0,
        'average_check' => 0.0,
        'first_visit_at' => null,
        'last_visit_at' => null,
    ];

    $devicesMap = [];
    $orders = [];

    foreach ($orderRows as $row) {
        $oid = (int)$row['id'];
        $rawStatus = (string)($row['status'] ?? 'Accepted');
        $canonicalStatus = canonicalOrderStatus($rawStatus);
        $statusLabel = getStatusLabel($rawStatus);
        $statusVariant = customerOrderStatusVariant($rawStatus);

        $deviceType = (string)($row['device_type'] ?? 'Other');
        $deviceBrand = trim((string)($row['device_brand'] ?? ''));
        $deviceModel = trim((string)($row['device_model'] ?? ''));
        $serial1 = trim((string)($row['serial_number'] ?? ''));
        $serial2 = trim((string)($row['serial_number_2'] ?? ''));
        $orderType = (string)($row['order_type'] ?? 'Non-Warranty');
        $isWarranty = OrderStatusService::isReclamationOrderType($orderType);
        $isHighPriority = (($row['priority'] ?? 'Normal') === 'High');

        $finalCost = $row['final_cost'] !== null ? (float)$row['final_cost'] : null;
        $estimatedCost = $row['estimated_cost'] !== null ? (float)$row['estimated_cost'] : null;
        $linkedInvoice = $latestOrderInvoiceMap[$oid] ?? null;

        if (!$financeAvailable) {
            $customerTotal = null;
        } elseif ($linkedInvoice !== null) {
            $customerTotal = (float)$linkedInvoice['total_amount'];
        } elseif ($finalCost !== null) {
            $customerTotal = $finalCost;
        } elseif ($estimatedCost !== null) {
            $customerTotal = $estimatedCost;
        } else {
            $customerTotal = null;
        }

        $createdAt = (string)($row['created_at'] ?? '');
        if ($mode === 'devices') {
            // Device grouping (newest order arrives first because of ORDER BY created_at DESC)
            $snNorm = strtoupper($serial1);
            $bmNorm = mb_strtolower(trim($deviceType . '|' . $deviceBrand . '|' . $deviceModel));
            $deviceKey = $snNorm !== '' ? ('sn:' . $snNorm) : ('bm:' . $bmNorm);

            if (!isset($devicesMap[$deviceKey])) {
                $devicesMap[$deviceKey] = [
                    'device_type' => $deviceType,
                    'device_type_label' => __($deviceType),
                    'device_icon_class' => crmDeviceIconClass($deviceType),
                    'device_brand' => $deviceBrand,
                    'device_model' => $deviceModel,
                    'serial_number' => $serial1,
                    'serial_number_2' => $serial2,
                    'visits_count' => 0,
                    'last_order_id' => $oid,
                    'last_visit_at' => $createdAt,
                    'last_status' => $canonicalStatus,
                    'last_status_label' => $statusLabel,
                    'last_status_variant' => $statusVariant,
                ];
            } elseif ($devicesMap[$deviceKey]['serial_number_2'] === '' && $serial2 !== '') {
                $devicesMap[$deviceKey]['serial_number_2'] = $serial2;
            }
            $devicesMap[$deviceKey]['visits_count']++;
            continue;
        }

        $shippingMethod = trim((string)($row['shipping_method'] ?? ''));
        $shippingLabel = $shippingMethod;
        if ($shippingMethod !== '' && OrderStatusService::isSelfPickupShippingMethod($shippingMethod)) {
            $shippingLabel = __('self_pickup');
        }

        $orders[] = [
            'id' => $oid,
            'customer_id' => (int)$row['customer_id'],
            'device_type' => $deviceType,
            'device_type_label' => __($deviceType),
            'device_icon_class' => crmDeviceIconClass($deviceType),
            'order_type' => $orderType,
            'order_type_label' => $isWarranty ? __('reclamation') : __('Non-Warranty'),
            'is_warranty' => $isWarranty,
            'device_brand' => $deviceBrand,
            'device_model' => $deviceModel,
            'serial_number' => $serial1,
            'serial_number_2' => $serial2,
            'appearance' => trim((string)($row['appearance'] ?? '')),
            'priority' => (string)($row['priority'] ?? 'Normal'),
            'is_high_priority' => $isHighPriority,
            'problem_description' => (string)($row['problem_description'] ?? ''),
            'technician_notes' => trim((string)($row['technician_notes'] ?? '')),
            'cancellation_reason' => trim((string)($row['cancellation_reason'] ?? '')),
            'estimated_cost' => $estimatedCost,
            'final_cost' => $finalCost,
            'customer_total' => $customerTotal,
            'has_final_cost' => $finalCost !== null,
            'status' => $rawStatus,
            'canonical_status' => $canonicalStatus,
            'status_label' => $statusLabel,
            'status_variant' => $statusVariant,
            'tech_name' => trim((string)($row['tech_name'] ?? '')),
            'created_at' => $createdAt,
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'status_date' => $statusDateByOrder[$oid] ?? (string)($row['updated_at'] ?? $createdAt),
            'shipping_method' => $shippingMethod,
            'shipping_method_label' => $shippingLabel,
            'shipping_tracking' => trim((string)($row['shipping_tracking'] ?? '')),
            'shipping_date' => $row['shipping_date'] ? (string)$row['shipping_date'] : null,
            'items' => $itemsByOrder[$oid] ?? [],
            'attachments' => $attachmentsByOrder[$oid] ?? [],
            'invoice' => $linkedInvoice,
        ];
    }

    if ($mode === 'overview') {
        $scope = ' FROM orders o WHERE o.customer_id = ?' . ($techScoped ? ' AND o.technician_id = ?' : '');
        $deviceSql = "CASE WHEN TRIM(COALESCE(serial_number,'')) <> ''
            THEN CONCAT('sn:', UPPER(TRIM(serial_number)))
            ELSE LOWER(CONCAT('bm:',TRIM(COALESCE(device_type,'Other')),'|',TRIM(COALESCE(device_brand,'')),'|',TRIM(COALESCE(device_model,'')))) END";
        $devicesStmt = $pdo->prepare("SELECT COUNT(DISTINCT BINARY ($deviceSql))" . $scope);
        $devicesStmt->execute($orderParams);
        $summary['unique_devices'] = (int)$devicesStmt->fetchColumn();

        $invoiceAmount = $isAdmin && $financeAvailable
            ? "(SELECT i.total_amount FROM invoices i WHERE i.order_id=o.id AND i.customer_id=o.customer_id
                AND i.status <> 'cancelled' AND COALESCE(i.invoice_type,'invoice') <> 'credit_note'
                ORDER BY COALESCE(i.payment_date,i.date_issue,i.created_at) DESC,i.id DESC LIMIT 1),"
            : '';
        $totalsSql = "SELECT o.status,o.order_type,COUNT(*) AS qty,MIN(o.created_at) AS first_visit,
            MAX(o.created_at) AS last_visit,SUM(COALESCE({$invoiceAmount}o.final_cost,o.estimated_cost,0)) AS amount";
        try {
            $totalsStmt = $pdo->prepare($totalsSql . $scope . ' GROUP BY o.status,o.order_type');
            $totalsStmt->execute($orderParams);
        } catch (PDOException $e) {
            if ($invoiceAmount === '') throw $e;
            error_log('Customer invoice totals failed: ' . $e->getMessage());
            $financeAvailable = false;
            $totalsStmt = $pdo->prepare(str_replace($invoiceAmount, '', $totalsSql) . $scope . ' GROUP BY o.status,o.order_type');
            $totalsStmt->execute($orderParams);
        }
        foreach ($totalsStmt as $group) {
            $qty = (int)$group['qty'];
            $status = canonicalOrderStatus($group['status']);
            $summary['total_orders'] += $qty;
            if (in_array($status, $activeStatuses, true)) {
                $summary['active_orders'] += $qty;
                $summary['active_estimate_total'] += (float)$group['amount'];
            }
            if ($status === 'Ready') $summary['ready_orders'] += $qty;
            if ($status === 'Issued') {
                $summary['issued_orders'] += $qty;
                $summary['total_spent'] += (float)$group['amount'];
            }
            if (in_array($status, $cancelledStatuses, true)) $summary['cancelled_orders'] += $qty;
            if (OrderStatusService::isReclamationOrderType($group['order_type'])) $summary['warranty_orders'] += $qty;
            $summary['first_visit_at'] = min($summary['first_visit_at'] ?? $group['first_visit'], $group['first_visit']);
            $summary['last_visit_at'] = max($summary['last_visit_at'] ?? $group['last_visit'], $group['last_visit']);
        }
    }
    $summary['average_check'] = $summary['issued_orders'] > 0
        ? round($summary['total_spent'] / $summary['issued_orders'], 2)
        : 0.0;
    $summary['total_spent'] = round($summary['total_spent'], 2);
    $summary['active_estimate_total'] = round($summary['active_estimate_total'], 2);

    if (!$financeAvailable) {
        $summary['total_spent'] = $summary['average_check'] = $summary['active_estimate_total'] = null;
    }
    if ($mode === 'devices') api_json_exit(['success' => true, 'devices' => array_values($devicesMap)]);
    if ($mode !== 'overview') api_json_exit(['success' => true, 'orders' => $orders, 'has_more' => $hasMore]);
    $phoneRaw = trim((string)($customerRow['phone'] ?? ''));
    $phoneClean = preg_replace('/[^0-9+]/', '', $phoneRaw);
    $contactName = trim((string)($customerRow['first_name'] ?? '') . ' ' . (string)($customerRow['last_name'] ?? ''));

    $customerPayload = [
        'id' => (int)$customerRow['id'],
        'customer_type' => ($customerRow['customer_type'] ?? 'private') === 'company' ? 'company' : 'private',
        'first_name' => (string)($customerRow['first_name'] ?? ''),
        'last_name' => (string)($customerRow['last_name'] ?? ''),
        'company' => (string)($customerRow['company'] ?? ''),
        'ico' => (string)($customerRow['ico'] ?? ''),
        'dic' => (string)($customerRow['dic'] ?? ''),
        'phone' => $phoneRaw,
        'phone_clean' => is_string($phoneClean) ? $phoneClean : '',
        'email' => (string)($customerRow['email'] ?? ''),
        'address' => (string)($customerRow['address'] ?? ''),
        'created_at' => (string)($customerRow['created_at'] ?? ''),
        'display_name' => crmCustomerDisplayName($customerRow),
        'contact_name' => $contactName,
        'can_create_order' => currentUserCanCreateOrderForCustomer($customer_id),
        'can_edit_customer' => hasPermission('edit_customers'),
        'is_admin' => $isAdmin,
        'currency' => $currency,
    ];

    api_json_exit([
        'success' => true,
        'customer' => $customerPayload,
        'summary' => $summary,
        'devices' => [],
        'has_more' => $hasMore,
        'invoice_count' => $invoiceCount ?? null,
        'finance_available' => $financeAvailable,
        'orders' => $orders,
        'invoices' => $invoicesPayload,
    ]);
} catch (Throwable $e) {
    api_json_exit(['success' => false, 'message' => publicExceptionMessage($e)], 500);
}
