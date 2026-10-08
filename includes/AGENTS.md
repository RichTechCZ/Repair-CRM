# Includes Documentation

## Purpose
Contains shared configuration, language, common functions, header, and footer for the CRM web interface.

## Ownership
Root AGENTS.md -> includes/AGENTS.md

## Local Contracts
- Reusable components and configuration.
- `api_bootstrap.php` — shared API guards (`api_bootstrap`, `api_json_exit`, `api_exception_exit`).
- `reports_stats.php` — batched `getDetailedStatsBatch()` / compatible `getDetailedStats()` financial/ops reporting, plus `crmGetTechnicianPayroll()` for per-employee 80 mm payroll lines. One request performs a constant number of queries; operational dates come from status transitions, not `orders.updated_at`.
- `statistics_dashboard.php` — read-only statistics service for `statistics.php`; resolves period ranges and technician scope from the authenticated session, derives lifecycle timing from `order_status_log`, and reuses the binding finance formulas without accepting a technician selector from the request.
- `content_security_policy.php` — enforced response CSP: trusted templates use an explicit per-response script nonce and inline event attributes are forbidden. `header.php` bootstraps this module if an older production `config.php` did not load it, so `crmCspNonce()` never fatals. Allowed image hosts include `self`, `data:`, `blob:`, `https://api.qrserver.com` (phone QR), and `https://flagcdn.com` (language flags).
- `sensitive_data.php` — AES-256-GCM device-PIN encryption using the stable base64 `CRM_DATA_ENCRYPTION_KEY`. Must be loaded from `config.php` after `loadEnv()` so authorized order views can decrypt; never render `enc:v1:` ciphertext in the UI. `orders.pin_code` must be `TEXT` (not `VARCHAR(50)`): ciphertext for a short PIN is ~51+ chars.
- `header.php` / `footer.php` — shared application shell (sidebar, top bar, search, skip link, modal/live-region scaffolding); local CSS and `assets/js/main.js` use a `filemtime` version query so deployed asset updates bypass stale browser caches. `header.php` resolves contextual page titles before `<title>` so browser tabs match the current page.
- `getStatusBadge()` — localized semantic status component; maps lifecycle values to `.status-pill--*` variants and must not return raw Bootstrap `badge bg-*` classes.
- `getInvoiceStatusBadge()` — invoice lifecycle pills (draft / issued / paid / overdue / cancelled) using the same `.status-pill` system; accounting UI must not use raw Bootstrap status badges.
- `getDeviceIcon()` — returns a consistent Font Awesome device icon for list rows; do not restore emoji-based device icons.
- `sendTelegramNotification()` — best-effort HTML delivery helper. Dynamic values must pass through `telegramHtml()` before interpolation; missing cURL or transport errors are logged and return `false`, never interrupt an order workflow.
- `telegram_bot.php` — Telegram Bot API transport, interactive keyboards, FSM state management (`telegram_bot_states`), media attachment downloading with `upload_security.php` validation, and role/scoping resolution.
- `telegram_webhook_security.php` — pure Telegram webhook secret and URL validation helpers; webhook requests fail closed when the secret is missing or invalid.
- `request_security.php` — pure HTTPS-request detection; proxy headers are considered only when `CRM_TRUST_PROXY_HTTPS=1` is explicitly configured.
- `upload_security.php` — the only order-attachment storage policy. It detects MIME server-side, assigns the extension, enforces 10-file and per-media size limits, writes random names, and protects `uploads/` from script execution.
- `migration_runner.php` — shared idempotent migration registry/runner used by CLI deployment migrations.
- `partials/` — page-specific PHP-rendered scripts (`orders_scripts.php`, `view_order_scripts.php`). Issued + order type «Рекламация» (`Warranty`) or Self Pickup must not show «Для выдачи необходимо заполнить финальную стоимость». `effectiveShippingMethod()` must stay in script/global scope so `#confirmStatusBtn` can post the Issued update without hanging the confirm modal.
- `isTechnicianScoped()` / `currentTechnicianId()` — technician isolation helpers (admins and `admin_access` are not scoped).
- `publicExceptionMessage(Throwable $e)` — safe client-facing errors; logs PDO/system details.
- `crmBackupDirectory($create = false)` — absolute path to SQL dumps (`app/backup_db/` by default, optional `CRM_BACKUP_DIR`); when `$create` is true, ensures the directory and deny-web guards exist.
- Order/customer access helpers: `currentUserCanViewOrder`, `currentUserCanEditOrder`, `currentUserCanViewCustomer`; first-order onboarding additionally uses a single-session 15-minute customer grant.
- `crmGetPublicOrderStatusByToken()` returns a minimal public payload (status, device, timestamps, public id/order number) without customer PII.

### Permission matrix (binding)
| Key | Who gets it | Effect |
|-----|-------------|--------|
| session `role=admin` | `users` table login | All permissions true |
| `admin_access` | tech_permissions | Full CRM; not technician-scoped |
| *(none for orders)* | every technician by default | View/edit **only** own orders via `technician_id` |
| `edit_customers` | tech_permissions | Customers UI/API; techs stay customer-scoped |
| `manage_passwords` | tech_permissions | Change admin passwords in settings |

- Assignable keys: only `getAvailablePermissions()` / `getAllowedPermissionKeys()`.
- `setTechPermissions()` whitelist-filters input and calls `purgeObsoleteTechPermissions()`.
- **Never reintroduce** `view_all_orders` / `edit_orders` — they contradicted isolation and were never enforced.

## Work Guidance
- New shared auth/scope/error helpers go here, not duplicated in endpoints.
- Runtime code must not execute DDL. Missing required tables/columns fail closed and are repaired only by CLI migrations. The login throttle may temporarily allow credential verification when the specific `login_attempts` table or column is unprovisioned, but it must log the schema gap; all other runtime/store failures remain fail-closed.
- Global order search covers customer name/company/email, device brand/model, both serial-number fields, order IDs, normalized phone digits, and `orders.created_at` date ranges. Dashboard topbar search and Orders page search both call `searchOrdersList()` so scoring, technician scoping, and the optional-index fallback stay identical. FULLTEXT/prefix indexes accelerate when present; when production schema lacks them the helper retries the baseline LIKE/date path. S/N/IMEI fields support infix identifier matching for pasted fragments and bare numeric IMEI values; never search encrypted `pin_code`.
- Authenticated sessions expire after 2 hours idle or 12 hours absolute; successful login resets both clocks.
- New permission keys must be added to `getAvailablePermissions()`, enforced with `hasPermission()`, and documented in this matrix.
- Keep shell-level accessibility in shared includes: page structure, skip-link targets, consistent navigation state, and global status/live regions belong here rather than in page files.
- Keep the shared top bar contextual but title-free when the page already provides an `h1`; it carries navigation, search, and account controls, while Orders and Dashboard visually join it to their shared `.workspace-overview`. On Orders, render the order search once inside the overview head, between page context and the primary action; keep the top bar search-free there. On Dashboard, the topbar order search uses the same placeholder/fields contract as Orders and the same `searchOrdersList()` engine. A searchless top bar is hidden on desktop, while its compact mobile controls remain available for sidebar navigation.
- The compact sidebar must be removed from keyboard navigation while closed and expose its expanded state to assistive technology.

## Verification
- `php -l includes/header.php` after shared asset-link changes.
- `php -l includes/functions.php` after helper changes.
- `php -l includes/telegram_bot.php includes/telegram_webhook_security.php` after telegram changes.
- `php -l includes/request_security.php` after request-security-helper changes.
- `php -l includes/upload_security.php includes/migration_runner.php` after upload or migration changes.
- `php -l includes/content_security_policy.php includes/sensitive_data.php includes/reports_stats.php` after CSP, encrypted-data, or reporting changes.

## Child DOX Index
None.
