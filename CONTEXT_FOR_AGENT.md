# CRM Project Handover - State as of 2026-01-31

This document provides a summary of the CRM project for the next OpenClaw agent.

## Project Info
- **Location:** `h:/MY PROJECT/crm/`
- **Main Stack:** PHP (Vanilla), MySQL (PDO), Bootstrap 5, jQuery.

## 🚀 Recent Accomplishments
1.  **Quick Edit Modal (`orders.php`):** Clicking an order ID now opens a modal for instant editing (status, tech, cost, notes, media preview) without page reload. Logic handles inventory auto-updates on status change.
2.  **Advanced Printing:** 
    - `print_order.php`: Standard A4.
    - `print_workshop.php`: Detailed technician work order with PIN/S/N highlights and manual notes field.
    - `print_thermal.php`: 80mm receipt optimized for EPSON TM-T70 (includes QR for status check).
3.  **Shipping & Logistics:** Added tracking for Zasilkovna, PPL, etc., including Tracking ID and delivery timestamps.
4.  **Financials:** Added `extra_expenses` field for orders. Admin reports now show Gross Revenue, Extra Expenses, and Net Profit (Revenue - Parts Cost - Extra Expenses).
5.  **Customer Management:** 
    - Private vs Company types.
    - **ARES Integration:** IČO lookup automatically fills company name, DIČ, and address.
6.  **UX/UI Improvements:** 
    - Icons added to all input labels in `edit_order.php`.
    - Device type icons (📱, 💻, etc.) used globally.
    - Smart search with ID priority.
7.  **Localization:** Full support for RU/CS in `lang.php`.

## 🛠 Database Changes (Applied)
- `orders` table: `shipping_method`, `shipping_tracking`, `shipping_date`, `extra_expenses`, `serial_number_2`.
- `customers` table: `customer_type` (ENUM), `ico`, `dic`, `company`.

## 📂 Key Files
- `lang.php`: All translations.
- `functions.php`: Core helpers (`formatMoney`, `get_setting`).
- `view_order.php`: Detailed order management.
- `api/update_order_full.php`: Universal backend for order edits.
- `api/get_order_details.php`: Fetcher for modals.

## 📝 Ongoing Tasks / Future Work
- Verify Telegram notification triggers for all status changes.
- Implement more granular permissions for inventory management.
- Add history/logs for order status changes.

---
*Created by Pi (OpenClaw Agent) for seamless project continuation.*
