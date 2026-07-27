# Assets Documentation

## Purpose
Static assets such as CSS and JavaScript files for the front-end.

## Ownership
Root AGENTS.md -> assets/AGENTS.md

## Local Contracts
- `css/` — global theme (`style.css`, `login.css`).
- `js/main.js` — shared UI (sidebar, global modals, Select2/Fancybox init).
- Page-heavy interactive logic still lives in `includes/partials/*_scripts.php` (PHP i18n); prefer extracting pure JS here with a small `window.*Config` bag when touching those pages again.

## Work Guidance
- New shared browser helpers go in `js/main.js` or a dedicated `js/*.js` file linked from `includes/header.php` / the page.
- Shared UI follows the premium CRM shell in `css/style.css`: restrained dark palette, warm accent, custom shell/navigation marks, minimal motion (with full `prefers-reduced-motion` support and subtle contextual animations only), and no generic glassmorphism or neon AI styling.
- Keep new motion subtle and contextual; respect `prefers-reduced-motion`, visible focus states, and keyboard-accessible interactions.
- Recent fixes (2026-07-24): Enhanced reduced-motion handling, improved mobile table wrapping, removed potential inline style conflicts, restored `.phone-qr-trigger` QR code popover functionality, and made metric cards + page header more compact (reduced padding/font sizes while preserving readability and mobile responsiveness). All changes documented in CHANGELOG.md.

## Verification
- Manual smoke of dashboard/orders after asset changes.

## Child DOX Index
None.
