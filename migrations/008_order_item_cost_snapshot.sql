-- Snapshot of the part purchase cost on each order line.
-- Payroll used the live inventory.cost_price, so editing a part's cost silently changed
-- payouts that were already paid. The backfill copies today's values exactly (including 0),
-- so historical report results do not change. Unknown cost stays NULL (falls back to
-- order_items.price per the binding formula).
-- Executed by crmApplyOrderItemCostSnapshotMigration() in includes/migration_runner.php.
ALTER TABLE `order_items` ADD COLUMN `cost_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `price`;
UPDATE `order_items` oi
  JOIN `inventory` i ON i.id = oi.inventory_id
   SET oi.cost_price = i.cost_price
 WHERE oi.cost_price IS NULL AND i.cost_price IS NOT NULL;
