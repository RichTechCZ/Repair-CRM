# Models Documentation

## Purpose
Contains class definitions and object-oriented models for business logic (invoices, order status, Telegram bot router, AI).

## Ownership
Root AGENTS.md -> models/AGENTS.md

## Local Contracts
- `InvoiceManager.php` / `InvoiceAutomation.php` / `MyInvoiceApiClient.php` — accounting and MyInvoice sync.
- `resolveInvoiceTotal()` in `InvoiceAutomation.php` derives the customer charge from `final_cost` (or `estimated_cost` only when final cost is absent); never add order-item selling prices to an invoice total.
- `OrderStatusService.php` — shared order status transition rules: terminal lock, Issued validation, inventory consume/return, auto-invoice, admin TG notify, technician reassignment TG. Issued requires a handover method. `Self Pickup` and order type `Warranty` / «Рекламация» skip the final-cost gate. Paid carrier handover still needs a positive final cost. `syncStatusHistoryDate()` applies a manual Status date to the current history row only. `applyInTransaction()` stamps `shipping_date` (if empty) on every transition into Issued, so every path (quick edit, full edit, Telegram, sync) lands in a finance period. `assertClosedOrderEditable()` is the closed-order lock for money/parts/dates/handover.
- `TelegramBotRouter.php` — Telegram Bot routing engine handling inline callback queries, interactive menus, FSM state processing, technician-scoped order management, media upload delegation, notes editing, spare parts addition/removal, and personal/workshop financial reporting. Status commits re-validate the terminal lock, Issued handover/final-cost (Warranty and Self Pickup rules) and the cancellation reason under `FOR UPDATE`; reports read the real `getDetailedStatsBatch()` keys (`revenue`, `parts_cost`, `expenses`, `earnings`, `sc_income`, `finance_orders`, `engineer_rate`); user-facing errors go through `publicExceptionMessage()`.
- `InvoiceManager.php` validates dates, status/payment allowlists, order/customer linkage, bounded item counts, and finite non-negative line values before persisting under row locks.
- `InvoiceManager::createCreditNote()` accepts only issued/paid/overdue non-credit source invoices, serializes gap-safe numbering, prevents duplicate active full credit notes, links the source order, and stores negative totals/VAT/unit prices.
- `MyInvoiceApiClient.php` sends bearer credentials only over verified HTTPS (plain HTTP is allowed solely for loopback development hosts). The base URL comes only from `MYINVOICE_API_BASE_URL` (env), like the token; it is not editable in settings.
- `InvoicePolicy.php` — VAT treatment (`crmInvoiceVatPolicy()`, `crmInvoiceAmountsFromCustomerTotal()`) and regular invoice numbering (`crmReserveInvoiceNumber()` inside a transaction, `crmAdvanceInvoiceCounterPast()`, `crmSuggestInvoiceNumber()` for UI pre-fill). Every invoice creator must use it.
- `nvidia_ai.php` — optional AI helpers.

## Work Guidance
- New multi-endpoint domain rules belong here (not copied across `api/*.php`).
- Keep functions/classes free of HTTP output; endpoints call them and format JSON.

## Verification
- `php -l models/*.php` after changes.

## Child DOX Index
None.
