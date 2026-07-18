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
     * Issued requires positive final cost; optionally shipping method.
     *
     * @throws Exception
     */
    public static function assertIssuedRequirements(
        string $canonicalNew,
        $finalCost,
        ?string $shippingMethod = null,
        bool $requireShipping = true
    ): void {
        if ($canonicalNew !== 'Issued') {
            return;
        }
        if ($finalCost === null || $finalCost === '' || (float)$finalCost <= 0) {
            throw new Exception(__('required_for_issue'));
        }
        if ($requireShipping && ($shippingMethod === null || trim((string)$shippingMethod) === '')) {
            throw new Exception(__('required_for_issue'));
        }
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

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $link = $protocol . ($_SERVER['HTTP_HOST'] ?? '') . '/view_order.php?id=' . $orderId;
        $msg = sprintf(__('tg_new_order'), $orderId) . "\n";
        $msg .= sprintf(
            __('tg_device'),
            trim(($orderSnapshot['device_brand'] ?? '') . ' ' . ($orderSnapshot['device_model'] ?? ''))
        ) . "\n";
        $msg .= sprintf(
            __('tg_problem'),
            mb_substr((string)($orderSnapshot['problem_description'] ?? ''), 0, 100)
        ) . "\n";
        $msg .= sprintf(__('tg_open_link'), $link);
        sendTelegramNotification($techTelegram, $msg);
    }
}
