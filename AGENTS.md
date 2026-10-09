# DOX framework

- DOX is highly performant AGENTS.md hierarchy installed here
- Agent must follow DOX instructions across any edits

## Core Contract

- AGENTS.md files are binding work contracts for their subtrees
- Work products, source materials, instructions, records, assets, and durable docs must stay understandable from the nearest applicable AGENTS.md plus every parent AGENTS.md above it

## Read Before Editing

1. Read the root AGENTS.md
2. Identify every file or folder you expect to touch
3. Walk from the repository root to each target path
4. Read every AGENTS.md found along each route
5. If a parent AGENTS.md lists a child AGENTS.md whose scope contains the path, read that child and continue from there
6. Use the nearest AGENTS.md as the local contract and parent docs for repo-wide rules
7. If docs conflict, the closer doc controls local work details, but no child doc may weaken DOX

Do not rely on memory. Re-read the applicable DOX chain in the current session before editing.

## Update After Editing

Every meaningful change requires a DOX pass before the task is done.

Update the closest owning AGENTS.md when a change affects:

- purpose, scope, ownership, or responsibilities
- durable structure, contracts, workflows, or operating rules
- required inputs, outputs, permissions, constraints, side effects, or artifacts
- user preferences about behavior, communication, process, organization, or quality
- AGENTS.md creation, deletion, move, rename, or index contents

Update parent docs when parent-level structure, ownership, workflow, or child index changes. Update child docs when parent changes alter local rules. Remove stale or contradictory text immediately. Small edits that do not change behavior or contracts may leave docs unchanged, but the DOX pass still must happen.

## Hierarchy

- Root AGENTS.md is the DOX rail: project-wide instructions, global preferences, durable workflow rules, and the top-level Child DOX Index
- Child AGENTS.md files own domain-specific instructions and their own Child DOX Index
- Each parent explains what its direct children cover and what stays owned by the parent
- The closer a doc is to the work, the more specific and practical it must be

## Child Doc Shape

- Create a child AGENTS.md when a folder becomes a durable boundary with its own purpose, rules, responsibilities, workflow, materials, or quality standards
- Work Guidance must reflect the current standards of the project or user instructions; if there are no specific standards or instructions yet, leave it empty
- Verification must reflect an existing check; if no verification framework exists yet, leave it empty and update it when one exists

Default section order:
- Purpose
- Ownership
- Local Contracts
- Work Guidance
- Verification
- Child DOX Index

## Style

- Keep docs concise, current, and operational
- Document stable contracts, not diary entries
- Put broad rules in parent docs and concrete details in child docs
- Prefer direct bullets with explicit names
- Do not duplicate rules across many files unless each scope needs a local version
- Delete stale notes instead of explaining history
- Trim obvious statements, repeated rules, misplaced detail, and warnings for risks that no longer exist

## Closeout

1. Re-check changed paths against the DOX chain
2. Update nearest owning docs and any affected parents or children
3. Refresh every affected Child DOX Index
4. Remove stale or contradictory text
5. Run existing verification when relevant
6. Report any docs intentionally left unchanged and why

## User Preferences

When the user requests a durable behavior change, record it here or in the relevant child AGENTS.md
- Shared CRM UI should avoid generic AI styling (glassmorphism, neon gradients, stock-looking iconography, excessive motion) and instead use a restrained premium interface with deliberate typography, limited accents, and subtle microinteractions.
- Order workflow states use localized, centralized tonal status pills (`getStatusBadge()` / `.status-pill`) rather than raw Bootstrap `badge bg-*` colours. Closed handover states (`Issued`, `Collected`) are calm neutral labels; they must remain clearly legible in dense tables.
- After an order is created, the operator must receive an explicit confirmation with a direct path to the new order or to creating the next one; form failures must return to the CRM with a safe, actionable message.
- Employees and technicians must only see and work with orders assigned to their own `technician_id`; technician-side broad permissions must not expose other technicians' orders.
- Technician-scoped users also may only list/search/edit customers they already share an order with; customer order lists (`get_customer_orders`) must not leak other technicians' orders for the same customer.
- Assignable tech permissions are only: `admin_access`, `edit_customers`. `manage_passwords` was removed (it let a technician take over an administrator account): administrator (`users`) passwords are changed only from a `role=admin` session, after re-entering that administrator's own current password, and every attempt is written to `system_errors` with type `audit`. Cross-tech order rights (`view_all_orders`, `edit_orders`) are removed and must not return; order access is always ownership-based unless the user has `admin_access` / session admin.
- System-admin sessions may be created only from the `users` table. Every `technicians` login remains `role=technician`; broader staff capability comes only from the explicit technician permission allowlist.
- Order attachments must use `includes/upload_security.php`: server-detected MIME-to-extension mapping, bounded size/count, cryptographically random names, and a non-executable `uploads/` directory. Client MIME and filename extensions are never trusted.
- Database changes run through `crmRunMigrations()` in `includes/migration_runner.php`; the production CLI deployment path must fail closed when a migration reports an error. Web-triggered application updates and production web migrations are disabled. The login throttle may temporarily allow credential verification when the specific `login_attempts` table or column is unprovisioned, but it must log the schema gap; all other runtime/store failures remain fail-closed.
- Telegram notifications to technicians are limited to newly created/assigned orders. Order status-change notifications go only to the administrator chat `2427615` unless a more specific `status_change_admin_telegram_id` setting is configured.
- Telegram webhooks require a non-empty `TELEGRAM_WEBHOOK_SECRET` and must reject requests without the matching Telegram secret header. The `api/test_tech_tg.php` transport check is admin-only.
- Financial reporting uses these binding formulas (centralized in `getDetailedStatsBatch()` / `getDetailedStats()` in `includes/reports_stats.php`):
  - Customer revenue is the `total_amount` from the latest non-credit invoice for the order. If there is no such invoice, use `final_cost`, then `estimated_cost`.
  - Never add `order_items.price × qty` to an invoice total: parts may already be included in the invoice, and doing so double-counts revenue.
  - Parts purchase cost = Σ `order_items.qty × order_items.cost_price` (the purchase cost snapshotted when the part is added; falls back to `inventory.cost_price`, then `order_items.price` when unknown). Unknown purchase cost is stored as NULL, never 0. Later inventory price edits must not change paid payroll.
  - Net profit (чистая прибыль) = customer revenue − parts purchase cost − extra expenses. Engineer payouts are NOT subtracted from net profit; they are tracked as a separate metric.
  - Engineer payout per order = max(0, customer revenue − parts purchase cost − extra expenses) × (technician rate / 100). The base is floored at 0 so engineers never owe the SC.
  - SC income (Доход СЦ) = net profit − total engineer payouts.
- VAT: the business is NOT a VAT payer (`acc_is_vat_payer` must stay 0). The order final cost / invoice `total_amount` is always the amount the customer pays; with the non-payer setting VAT is 0. All invoice creators use `models/InvoicePolicy.php` (`crmInvoiceAmountsFromCustomerTotal()`); should the setting ever be enabled, VAT is extracted from that amount and `invoice_items.price` is stored without VAT. Documents render from the invoice's own `is_vat_payer` snapshot. Engineer payouts are calculated from the customer revenue as defined above (unchanged).
- Invoice numbers for regular invoices come from one locked counter (`crmReserveInvoiceNumber()`); a manual number in the prefix series advances the counter. Credit notes keep their own gap-safe series.
- A reopened Issued order loses its `shipping_date`; re-issuing it books it in the period of the new handover. `sync_from_site.php` changes the price only at handover (never for already issued or open orders) and never reopens cancelled / issued-without-repair orders.
- An auto-created invoice that is already synced to MyInvoice is never cancelled automatically when the order is reopened; the accountant handles it (credit note).
- Customer data, payment data and order links are never sent to third-party services for rendering: QR codes are generated locally (`includes/qr_code.php`).
- Order attachments are private: `uploads/` is web-denied and files are served only by `api/media.php` after the order authorization check.
- Operational report periods use `order_status_log.changed_at`. Ordinary field edits must not rewrite that history. Deliberately editing the Status date (`update_order_dates`) must update the current status row in `order_status_log.changed_at`, and for Issued/Collected also `shipping_date`, so reports follow the corrected date.
- Device PINs are encrypted at rest with `CRM_DATA_ENCRYPTION_KEY`, decrypted only after an order authorization check, and excluded from global search.
- A scoped technician may create an order only for a previously shared customer or for a customer created in the same session under the 15-minute onboarding grant.
- Credit notes store negative totals/VAT/unit prices; exports must preserve signed values and select the accounting system's credit-note document type.
- Production web requests use only the least-privilege `DB_USER`. Schema migrations are CLI-only with a separate `DB_MIGRATION_USER`.
- The CRM web process never updates application files. Production releases are immutable external deployments followed by CLI migrations and an atomic hosting-layer activation/rollback.
- HTML responses enforce nonce-based CSP with `script-src-attr 'none'`; trusted templates must set `crmCspNonce()` explicitly and third-party browser dependencies must stay exact-version pinned with verified SRI or be self-hosted.
- Issuing an order with handover `Self Pickup` (`Клиент забрал сам`) is a complete handover: no delivery expenses, no positive final-cost gate, and no «Уведомление» / shipping-required modal. Order type `Warranty` / «Рекламация» also skips the final-cost-for-issue message. Paid (`Non-Warranty`) carrier handover still requires a positive final cost.
- Customer phone numbers that open the QR popover must not show a hover tooltip such as “Показать QR-код телефона”; keep an accessible `aria-label` only.
- A4 invoice print (`print_invoice.php`) keeps the `Vystavil:` label with an empty issuer name; do not print the session display name (for example `Администратор`).

## Child DOX Index

- [.github/AGENTS.md](.github/AGENTS.md): Continuous-integration quality workflow
- [api/AGENTS.md](api/AGENTS.md): API endpoints and integration scripts
- [assets/AGENTS.md](assets/AGENTS.md): CSS and JS front-end assets
- [includes/AGENTS.md](includes/AGENTS.md): Shared configuration and UI components
- [migrations/AGENTS.md](migrations/AGENTS.md): Database migrations
- [models/AGENTS.md](models/AGENTS.md): Object-oriented business logic models
- [tests/AGENTS.md](tests/AGENTS.md): Dependency-free regression tests and runner
