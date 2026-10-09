<?php
/**
 * Shared order status transition rules and side effects.
 *
 * Used by api/update_order_status.php and api/update_order_full.php so inventory,
 * invoices, logging, and notifications stay consistent.
 */

require_once __DIR__ . '/InvoiceAutomation.php';

final class OrderStatusService
{
    /** @var list<string> */
    public const TERMINAL_STATUSES = ['Issued', 'Issued Without Repair', 'Repair Cancelled'];

    /** Statuses that consume inventory (parts written off). */
    public const INVENTORY_CONSUMING = ['Ready', 'Issued'];

    /** @var list<string> */
    public const REASON_REQUIRED = ['Issued Without Repair', 'Repair Cancelled'];

    public static function isTerminal(string $canonicalStatus): bool
    {
        return in_array($canonicalStatus, self::TERMINAL_STATUSES, true);
    }

    public static function isInventoryConsuming(string $canonicalStatus): bool
    {
        return in_array($canonicalStatus, self::INVENTORY_CONSUMING, true);
    }

    /**
     * Non-admins cannot leave a terminal status.
     *
     * @throws Exception
     */
    public static function assertCanChangeFromTerminal(
        string $canonicalCurrent,
        string $canonicalNew,
        bool $isAdmin
    ): void {
        if (
            !$isAdmin
            && self::isTerminal($canonicalCurrent)
            && $canonicalNew !== $canonicalCurrent
        ) {
            throw new Exception(__('status_change_after_collected_forbidden'));
        }
    }

    /**
     * Money, parts and dates of a closed order feed revenue and engineer payouts;
     * after closure only admins may change them (technicians cannot raise their own payout).
     *
     * @throws Exception
     */
    public static function assertClosedOrderEditable(string $canonicalStatus, bool $isAdmin): void
    {
        if (!$isAdmin && self::isTerminal($canonicalStatus)) {
            throw new Exception(__('closed_order_edit_forbidden'));
        }
    }

    /**
     * Customer collected the device in person — no carrier and no delivery expense.
     */
    public static function isSelfPickupShippingMethod($shippingMethod): bool
    {
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$shippingMethod)), 'UTF-8');
        if ($normalized === '') {
            return false;
        }
        $normalized = strtr($normalized, [
            'á' => 'a', 'í' => 'i', 'é' => 'e', 'ý' => 'y', 'ů' => 'u', 'ú' => 'u', 'ě' => 'e',
            'č' => 'c', 'ř' => 'r', 'š' => 's', 'ž' => 'z', 'ň' => 'n', 'ť' => 't', 'ď' => 'd',
        ]);
        $aliases = [
            'self pickup',
            'self_pickup',
            'selfpickup',
            'pickup',
            'клиент забрал сам',
            'забрал сам',
            'самовывоз',
            'osobni odber',
            'osobniodeber',
        ];
        return in_array($normalized, $aliases, true);
    }

    /**
     * Issued needs a handover method. Self Pickup counts; an empty value does not.
     */
    public static function issuedShippingSatisfied($shippingMethod): bool
    {
        return trim((string)$shippingMethod) !== '';
    }

    /**
     * Delivery expense applies only to carrier/courier handover, never Self Pickup.
     */
    public static function issuedRequiresDeliveryExpense($shippingMethod): bool
    {
        return self::issuedShippingSatisfied($shippingMethod)
            && !self::isSelfPickupShippingMethod($shippingMethod);
    }

    /**
     * Warranty / reclamation jobs are not billed a final customer charge.
     */
    public static function isReclamationOrderType($orderType): bool
    {
        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$orderType)), 'UTF-8');
        if ($normalized === '') {
            return false;
        }
        $normalized = strtr($normalized, [
            'á' => 'a', 'í' => 'i', 'é' => 'e', 'ý' => 'y', 'ů' => 'u', 'ú' => 'u', 'ě' => 'e',
            'č' => 'c', 'ř' => 'r', 'š' => 's', 'ž' => 'z', 'ň' => 'n', 'ť' => 't', 'ď' => 'd',
        ]);
        return in_array($normalized, [
            'warranty',
            'reclamation',
            'reklamace',
            'рекламация',
            'гарантия',
            'гарантийный',
        ], true);
    }

    /**
     * Paid repairs need a positive final cost unless handover is Self Pickup
     * or the job is a reclamation/warranty order.
     */
    public static function issuedRequiresFinalCost($orderType, $shippingMethod = null): bool
    {
        if (self::isReclamationOrderType($orderType)) {
            return false;
        }
        if (self::isSelfPickupShippingMethod($shippingMethod)) {
            return false;
        }
        return true;
    }

    /**
     * Issued requires a handover method. Reclamation/warranty and Self Pickup
     * skip the positive final-cost gate. Paid carrier handover still needs it.
     *
     * @throws Exception
     */
    public static function assertIssuedRequirements(
        string $canonicalNew,
        $finalCost,
        ?string $shippingMethod = null,
        bool $requireShipping = true,
        $orderType = null
    ): void {
        if ($canonicalNew !== 'Issued') {
            return;
        }
        if ($requireShipping && !self::issuedShippingSatisfied($shippingMethod)) {
            throw new Exception(__('required_for_issue'));
        }
        if (!self::issuedRequiresFinalCost($orderType, $shippingMethod)) {
            return;
        }
        if ($finalCost === null || $finalCost === '' || (float)$finalCost <= 0) {
            throw new Exception(__('required_final_cost_for_issue'));
        }
    }

    /**
     * Normalize a datetime-local / SQL datetime from the Status date editor.
     */
    public static function parseManualStatusDate($raw): ?string
    {
        $value = trim(str_replace('T', ' ', (string)$raw));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return null;
        }
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        $year = (int)$date->format('Y');
        if ($year < 2000 || $year > 2100) {
            return null;
        }
        return $value;
    }

    /**
     * Move the current status-history row to the date shown in the Status editor.
     * Older history rows stay put.
     *
     * @return 'updated'|'inserted'
     */
    public static function syncStatusHistoryDate(
        PDO $pdo,
        int $orderId,
        string $currentStatus,
        string $changedAt
    ): string {
        $canonical = function_exists('canonicalOrderStatus')
            ? canonicalOrderStatus($currentStatus)
            : $currentStatus;
        $legacy = function_exists('getLegacyEquivalentStatus')
            ? getLegacyEquivalentStatus($canonical)
            : null;
        $statuses = array_values(array_unique(array_filter([
            $currentStatus,
            $canonical,
            $legacy,
        ], static function ($status) {
            return $status !== null && $status !== '';
        })));
        if ($statuses === []) {
            $statuses = [$currentStatus];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM order_status_log
             WHERE order_id = ? AND new_status IN ({$placeholders})
             ORDER BY changed_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(array_merge([$orderId], $statuses));
        $logId = $stmt->fetchColumn();

        if ($logId) {
            $update = $pdo->prepare('UPDATE order_status_log SET changed_at = ? WHERE id = ? AND order_id = ?');
            $update->execute([$changedAt, $logId, $orderId]);
            return 'updated';
        }

        $insert = $pdo->prepare(
            'INSERT INTO order_status_log (order_id, old_status, new_status, changed_by, changed_role, changed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $orderId,
            '',
            $canonical !== '' ? $canonical : $currentStatus,
            null,
            null,
            $changedAt,
        ]);
        return 'inserted';
    }

    /**
     * Cancellation reason for unrepaired terminal statuses.
     *
     * @throws Exception
     */
    public static function assertCancellationReason(
        string $canonicalNew,
        ?string $reason,
        bool $columnExists,
        bool $allowEmptyIfAlreadyStored = false,
        ?string $existingReason = null
    ): void {
        if (!$columnExists || !in_array($canonicalNew, self::REASON_REQUIRED, true)) {
            return;
        }
        $trimmed = trim((string)$reason);
        if ($trimmed !== '') {
            return;
        }
        if ($allowEmptyIfAlreadyStored && trim((string)$existingReason) !== '') {
            return;
        }
        throw new Exception(__('cancellation_reason'));
    }

    /**
     * Inventory, auto-invoice, logging for a status change.
     * Call inside an open DB transaction before commit.
     *
     * @return array{status_changed: bool, invoice_to_sync: int|null}
     */
    public static function applyInTransaction(
        PDO $pdo,
        int $orderId,
        string $oldStatus,
        string $newStatus,
        $finalCost = null
    ): array {
        $canonicalOld = canonicalOrderStatus($oldStatus);
        $canonicalNew = canonicalOrderStatus($newStatus);
        $statusChanged = ($oldStatus !== $newStatus);

        $invoiceToSync = null;
        if (!$statusChanged) {
            return ['status_changed' => false, 'invoice_to_sync' => null];
        }

        if ($canonicalNew === 'Issued' && $canonicalOld !== 'Issued') {
            // Finance periods are keyed on shipping_date: every path into Issued
            // (quick edit, full edit, Telegram, sync) must stamp it, or the order
            // silently disappears from revenue and payroll.
            $pdo->prepare('UPDATE orders SET shipping_date = IFNULL(shipping_date, CURRENT_TIMESTAMP) WHERE id = ?')
                ->execute([$orderId]);
        } elseif ($canonicalOld === 'Issued' && $canonicalNew !== 'Issued') {
            // A reopened order is re-issued later: its finance period is the new handover,
            // so the old handover date must not survive the reopen.
            $pdo->prepare('UPDATE orders SET shipping_date = NULL WHERE id = ?')->execute([$orderId]);
        }

        $wasConsuming = self::isInventoryConsuming($canonicalOld);
        $isConsuming = self::isInventoryConsuming($canonicalNew);

        if (!$wasConsuming && $isConsuming) {
            processOrderInventoryChange($orderId, $isConsuming, $wasConsuming);
            if ($canonicalNew === 'Ready' && get_setting('acc_auto_create_invoice', '0') == '1') {
                $invoiceResult = createLocalInvoiceForCompletedOrder($pdo, $orderId, $finalCost);
                if ($invoiceResult['success'] ?? false) {
                    $invoiceToSync = (int)$invoiceResult['id'];
                } else {
                    error_log(
                        'Auto invoice creation failed for order #' . $orderId . ': '
                        . ($invoiceResult['error'] ?? 'unknown error')
                    );
                }
            }
        } elseif ($wasConsuming && !$isConsuming) {
            processOrderInventoryChange($orderId, $isConsuming, $wasConsuming);
            cancelAutoInvoicesForOrder($pdo, $orderId);
        }

        logOrderStatusChange($orderId, $oldStatus, $newStatus);

        return [
            'status_changed' => true,
            'invoice_to_sync' => $invoiceToSync,
        ];
    }

    /**
     * Post-commit notifications and MyInvoice sync.
     *
     * @return mixed MyInvoice sync result or null
     */
    public static function afterCommit(
        PDO $pdo,
        int $orderId,
        string $oldStatus,
        string $newStatus,
        $finalCost = null,
        ?int $invoiceToSync = null,
        bool $notifyAdmin = true
    ) {
        $syncResult = null;
        if ($invoiceToSync) {
            $syncResult = syncInvoiceToMyInvoice($pdo, $invoiceToSync);
        }

        if ($notifyAdmin && $oldStatus !== $newStatus) {
            sendOrderStatusAdminNotification(
                $orderId,
                canonicalOrderStatus($newStatus),
                $finalCost
            );
        }

        return $syncResult;
    }

    /**
     * Telegram the newly assigned technician (assignment / reassignment only).
     */
    public static function notifyTechnicianReassignment(
        PDO $pdo,
        int $orderId,
        $previousTechId,
        $newTechId,
        array $orderSnapshot = []
    ): void {
        $prev = (int)($previousTechId ?? 0);
        $next = (int)($newTechId ?? 0);
        if (!$next || $next === $prev) {
            return;
        }

        $tech = $pdo->prepare('SELECT telegram_id FROM technicians WHERE id = ? AND is_active = 1');
        $tech->execute([$next]);
        $techTelegram = $tech->fetchColumn();
        if (!$techTelegram) {
            return;
        }

        // Configured origin, never the request Host header (empty in Telegram/CLI runs).
        $link = crmPublicBaseUrl() . '/view_order.php?id=' . $orderId;
        $msg = sprintf(__('tg_new_order'), $orderId) . "\n";
        $msg .= sprintf(
            __('tg_device'),
            telegramHtml(trim(($orderSnapshot['device_brand'] ?? '') . ' ' . ($orderSnapshot['device_model'] ?? '')))
        ) . "\n";
        $msg .= sprintf(
            __('tg_problem'),
            telegramHtml(mb_substr((string)($orderSnapshot['problem_description'] ?? ''), 0, 100))
        ) . "\n";
        $msg .= sprintf(__('tg_open_link'), telegramHtml($link));
        sendTelegramNotification($techTelegram, $msg);
    }
}
