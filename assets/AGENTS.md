# Assets Documentation

## Purpose
Static assets such as CSS and JavaScript files for the front-end.

## Ownership
Root AGENTS.md -> assets/AGENTS.md

## Local Contracts

- Reset customer actions on card changes. Load repair details and tab data on demand and discard responses from previous selections.
- `css/` — global theme (`style.css`, `login.css`).
- `js/print.js` — CSP-safe print/close and opt-in auto-print behavior for standalone documents.
- `js/main.js` — shared UI (sidebar, global modals, Select2/Fancybox init, shared `#phoneQrPopover` binding via `initPhoneQrPopover()` / `crmBindPhoneQrTriggers()`) and the explicit `data-crm-action` delegation allowlist used instead of inline event attributes (`open-customer-card`, `show-customer-orders`, …). `setSidebarOpen()` synchronizes `aria-expanded` on both `#sidebarCollapse` and `.ios-tabbar [data-tab-action="more"]`. `showAlert(message, title, onHidden)` supports a one-time `onHidden` callback before redirects/reloads. `initFormGuards()` implements `data-confirm` (on the submit button or form, via `showConfirm`) and `data-crm-once` (disables submit after a valid submit). `crmFetchJson()` is the JSON fetch wrapper: it rejects on HTTP errors, non-JSON bodies and `success:false` with a user-safe message. Accounting row actions (`edit-invoice`, `create-credit-note`, `export-pohoda`, `export-s3`, `delete-invoice`, `open-preview`, …) require this allowlist on the server; page scripts must expose handlers on `window.*`. Universal document preview accepts same-origin HTTP(S) URLs only and never interpolates a URL into HTML.
- `js/customers.js` — customers directory interactions and slide-over customer card modal (`#customerCardModal`): DOM-safe rendering of customer profile, KPIs, repair history filters/search, grouped devices, admin-only invoices, inline profile editing (`api/update_customer.php`) + ARES lookup, and clean hide/restore around `#universalPreviewModal`, `#receptionLangModal`, and Fancybox galleries.
- Page-heavy interactive logic still lives in `includes/partials/*_scripts.php` (PHP i18n); prefer extracting pure JS here with a small `window.*Config` bag when touching those pages again.
- `js/jquery-4.0.0.min.js` — pinned self-hosted jQuery loaded by `includes/header.php` with SRI; an upgrade means a new exact-version file and verified hash, never a floating CDN URL.
- `css/mobile.css` + `js/mobile.js` — the iPhone/phone layer (loaded below 992px): safe-area insets, 44pt targets, 16px inputs (no iOS zoom), bottom-sheet modals, and the bottom tab bar behaviour. Keep it additive to `style.css`, reuse its tokens, and add no glass/neon effects.
- `css/style.css` surfaces and buttons are flat solid tokens with hairline borders and soft layered shadows: no gradients, glow helpers, hover lifts, or stock filler labels. Radii come from `--radius-*`; numerals use tabular figures; the system UI font stack is intentional.

## Work Guidance
- New shared browser helpers go in `js/main.js` or a dedicated `js/*.js` file linked from `includes/header.php` / the page.
- Shared UI follows the premium CRM shell in `css/style.css`: restrained dark palette, warm accent, custom shell/navigation marks, solid surface cards (no blur glassmorphism), compact radii (≤0.75–0.9rem), letter-spacing 0 on body/headings, minimal motion (with full `prefers-reduced-motion` support), and no neon AI styling or decorative orbs/grid overlays.
- The new-order device picker uses equal radio cards with the icon above a short label (`PC` stays abbreviated), six columns in the desktop modal, three on tablet, and two on mobile. Selected, hover, and keyboard-focus states must remain distinct without increasing motion.
- The new-order modal header uses the dedicated `.new-order-modal__*` and `.new-order-copy*` components: warm-accent title marker, compact copy/close controls, no inline sizing or generic Bootstrap dark/info treatment, and a full-width copy control below the title on mobile. Density for `#newOrderModal` is tightened only via CSS (smaller paddings, gutters, field heights, device cards) — field order and submit behaviour stay unchanged.
- The top bar is a compact utility area visually joined to `.workspace-overview` on Dashboard and Customers. On Orders, render search once in the overview. Below 768px, the bottom tabs carry navigation and the primary New Order action, and only Dashboard, Customers and Inventory use the top bar for page search.
- Select2: when `escapeMarkup` is a no-op, templates must return nodes built with `.text()` or escaped strings. With CSP `'strict-dynamic'`, jQuery HTML insertion of user data executes scripts.
- `showPrompt(label, onSubmit, title)` is the shared required-text modal (`#globalPromptModal` in `footer.php`); never use `window.prompt`. Language choosers use local `.lang-code` chips, not third-party flag images.
- `showConfirm(message, onConfirm, title, tone)`: the OK button is primary by default; pass `'danger'` for destructive actions. Empty tables/lists use `.empty-state` + `.empty-state__mark`. Media tile delete buttons use `.media-tile__remove` (≥ 2rem, 44px on coarse pointers). Fancybox is bound once in `main.js`. Non-blocking feedback uses `.crm-toast` (`--success` / `--danger`), not Bootstrap `text-bg-*`.
- Keyboard focus on `.btn`, `.nav-link` and `.page-link` is a solid 2px `--accent` outline (fields keep the soft ring); never set `outline: none` on them. `--text-tertiary` must stay at least 4.5:1 on `--bg-panel-strong`.
- Keep new motion subtle and contextual; respect `prefers-reduced-motion`, visible focus states, and keyboard-accessible interactions.
- Order states use `.status-pill` tokens in `css/style.css`; preserve a restrained tonal treatment, readable light text, and a non-colour text label. Inventory stock and priority chips also use `.status-pill` variants (`--stock-*`, `--priority-*`). Avoid raw Bootstrap `badge bg-*` for status/priority in operator UI.
- Invoice states reuse `.status-pill` with accounting variants (`--draft`, `--inv-issued`, `--paid`, `--overdue`). The accounting page uses `page-header` + `workspace-overview--accounting` metrics, `glass-card` tables, and compact row actions (preview/edit + overflow menu) — not rainbow outline button rows or emoji tabs.
- The Dashboard reuses the joined overview, four queue filters and mobile order cards. The monthly finance panel and the administrator’s workload table follow the order list on compact screens.
- `statistics.php` uses the `.statistics-*` component family in `css/style.css`: solid restrained panels, responsive KPI/breakdown grids, and `.status-pill` for lifecycle labels. Keep the dashboard read-only and avoid duplicating the financial/report shell when extending it.
- `customers.php` shares the joined `.workspace-overview--customers` shell, `.customer-directory-*` table/sort toolbar, and the slide-over `.customer-card-modal` drawer (right-docked sheet on desktop, full-height sheet on phones in `css/mobile.css`), keeping `.qr-popover` (`z-index: 1065`) and `.fancybox__container` (`z-index: 1085`) above modals.
- `prefers-reduced-motion` must shorten/disable **animations and transitions**, not strip permanent `transform` used for layout or icon geometry (skip-link offset, rotated CSS icons). Disable hover lifts selectively under that media query instead of a blanket `transform: none`.
- `.phone-qr-trigger` opens `#phoneQrPopover` (click/keyboard always; hover only when `(hover: hover) and (pointer: fine)`). Do not put a native `title` tooltip on the phone number. Enter/Space focuses Call inside the active modal’s focus trap. Escape closes the QR popover and restores focus to the trigger. Outside tap/click and scroll close the popover. Call control is ≥44px.
- Mobile / tablet shell (≤991.98px drawer, ≤767.98px phone cards):
  - Sidebar is a fixed dialog with an independently scrollable body, safe-area padding, and an explicit close button. Keep focus in the drawer while the shell and tab bar are inert. Escape, the close button and the backdrop restore focus to the trigger.
  - Dense tables use `.table-mobile-cards` and localized cell labels on phones. Order lists place ID and status in the first row of each card. Invoice line items also stack so quantity, price and VAT fields stay in view.
  - Full-height phone forms use a flex column for the inner form and scroll only the modal body, keeping the footer visible. Size to the visual viewport while the software keyboard is open. Avoid injecting Bootstrap scrollable/fullscreen classes into dialogs whose header, body and footer share a form.
  - Row actions and dropdown items use ≥44px touch targets on compact viewports.

## Verification
- Manual smoke of dashboard/orders after asset changes.
- Phone/tablet smoke: check the drawer and focus return, order cards and status shortcut, phone QR, quick status and print menus, and the fixed form footer while scrolling New Order and invoice items.

## Child DOX Index
None.
