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

## Verification
- Manual smoke of dashboard/orders after asset changes.

## Child DOX Index
None.
