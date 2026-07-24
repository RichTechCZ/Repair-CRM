# Includes Documentation

## Purpose
Contains shared configuration, language, common functions, header, and footer for the CRM web interface.

## Ownership
Root AGENTS.md -> includes/AGENTS.md

## Local Contracts
- Reusable components and configuration.
- `api_bootstrap.php` — shared API guards (`api_bootstrap`, `api_json_exit`, `api_exception_exit`).
- `reports_stats.php` — `getDetailedStats()` financial/ops reporting. Revenue comes from the latest non-credit invoice total (fallback `final_cost`, then `estimated_cost`); never add order-item prices to that invoice total.
- `partials/` — page-specific PHP-rendered scripts (`orders_scripts.php`, `view_order_scripts.php`).
- `isTechnicianScoped()` / `currentTechnicianId()` — technician isolation helpers (admins and `admin_access` are not scoped).
- `publicExceptionMessage(Throwable $e)` — safe client-facing errors; logs PDO/system details.
- Order/customer access helpers: `currentUserCanViewOrder`, `currentUserCanEditOrder`, `currentUserCanViewCustomer` (customer access for techs = has at least one assigned order).

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
- New permission keys must be added to `getAvailablePermissions()`, enforced with `hasPermission()`, and documented in this matrix.

## Verification
- `php -l includes/functions.php` after helper changes.

## Child DOX Index
None.
