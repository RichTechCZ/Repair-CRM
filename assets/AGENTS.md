# Assets Documentation

## Purpose
Static assets such as CSS and JavaScript files for the front-end.

## Ownership
Root AGENTS.md -> assets/AGENTS.md

## Local Contracts
- `css/` — global theme (`style.css`, `login.css`).
- `js/print.js` — CSP-safe print/close and opt-in auto-print behavior for standalone documents.
- `js/main.js` — shared UI (sidebar, global modals, Select2/Fancybox init) and the explicit `data-crm-action` delegation allowlist used instead of inline event attributes. Accounting row actions (`edit-invoice`, `create-credit-note`, `export-pohoda`, `export-s3`, `delete-invoice`, `open-preview`, …) require this allowlist on the server; page scripts must expose handlers on `window.*`. Universal document preview accepts same-origin HTTP(S) URLs only and never interpolates a URL into HTML.
- Page-heavy interactive logic still lives in `includes/partials/*_scripts.php` (PHP i18n); prefer extracting pure JS here with a small `window.*Config` bag when touching those pages again.

## Work Guidance
- New shared browser helpers go in `js/main.js` or a dedicated `js/*.js` file linked from `includes/header.php` / the page.
- Shared UI follows the premium CRM shell in `css/style.css`: restrained dark palette, warm accent, custom shell/navigation marks, solid surface cards (no blur glassmorphism), compact radii (≤0.75–0.9rem), letter-spacing 0 on body/headings, minimal motion (with full `prefers-reduced-motion` support), and no neon AI styling or decorative orbs/grid overlays.
- The new-order device picker uses equal radio cards with the icon above a short label (`PC` stays abbreviated), six columns in the desktop modal, three on tablet, and two on mobile. Selected, hover, and keyboard-focus states must remain distinct without increasing motion.
- The new-order modal header uses the dedicated `.new-order-modal__*` and `.new-order-copy*` components: warm-accent title marker, compact copy/close controls, no inline sizing or generic Bootstrap dark/info treatment, and a full-width copy control below the title on mobile. Density for `#newOrderModal` is tightened only via CSS (smaller paddings, gutters, field heights, device cards) — field order and submit behaviour stay unchanged.
- The top bar is a compact utility area (navigation, search, account), visually joined to the reusable `.workspace-overview` on Orders and Dashboard. The overview contains title, primary action, and dense inline metrics; on Orders, its search belongs once between the page context and primary action. Hide the visually empty `.topbar-shell--searchless` on desktop and retain its compact mobile navigation controls. Do not duplicate a page title in both regions or recreate standalone metric-card grids for these pages.
- Keep new motion subtle and contextual; respect `prefers-reduced-motion`, visible focus states, and keyboard-accessible interactions.
- Order states use `.status-pill` tokens in `css/style.css`; preserve a restrained tonal treatment, readable light text, and a non-colour text label. Inventory stock and priority chips also use `.status-pill` variants (`--stock-*`, `--priority-*`). Avoid raw Bootstrap `badge bg-*` for status/priority in operator UI.
- Invoice states reuse `.status-pill` with accounting variants (`--draft`, `--inv-issued`, `--paid`, `--overdue`). The accounting page uses `page-header` + `workspace-overview--accounting` metrics, `glass-card` tables, and compact row actions (preview/edit + overflow menu) — not rainbow outline button rows or emoji tabs.
- `statistics.php` uses the `.statistics-*` component family in `css/style.css`: solid restrained panels, responsive KPI/breakdown grids, and `.status-pill` for lifecycle labels. Keep the dashboard read-only and avoid duplicating the financial/report shell when extending it.
- `prefers-reduced-motion` must shorten/disable **animations and transitions**, not strip permanent `transform` used for layout or icon geometry (skip-link offset, rotated CSS icons). Disable hover lifts selectively under that media query instead of a blanket `transform: none`.
- `.phone-qr-trigger` opens `#phoneQrPopover` (click/keyboard always; hover only when `(hover: hover) and (pointer: fine)`). Do not put a native `title` tooltip on the phone number. Keyboard: Enter/Space focuses Call; Escape restores focus to the trigger; focus may move into the popover without it closing. Outside tap/click and scroll close the popover. Call control is ≥44px.
- Mobile / tablet shell (≤991.98px drawer, ≤767.98px phone cards):
  - Sidebar is a fixed drawer with backdrop, body scroll lock, safe-area padding, and close on Escape / backdrop / nav link.
  - `#main-content` (not the whole `#content` topbar) is `inert` while the drawer is open so the hamburger stays usable.
  - Dense list pages use `.table-mobile-cards` + `data-label` on cells to stack rows into cards on phones (orders, dashboard, customers, inventory, accounting).
  - Large modals must NOT auto-receive Bootstrap `modal-dialog-scrollable` / `modal-fullscreen-sm-down` when header/body/footer live inside a `<form>` (breaks New Order). Mobile sizing uses CSS max-height on `.modal-body` instead; `enhanceMobileChrome()` only marks coarse pointers and strips legacy injected classes.
  - Row actions and dropdown items use ≥44px touch targets on compact viewports.

## Verification
- Manual smoke of dashboard/orders after asset changes.
- Phone/tablet smoke: open/close sidebar (menu, backdrop, Escape, nav link); orders list as cards; QR phone toggle + outside close; quick-status / print dropdowns; new-order modal full-screen on small width.

## Child DOX Index
None.
