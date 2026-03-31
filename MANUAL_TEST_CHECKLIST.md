# Manual Test Checklist

Use an admin account unless a step says technician.

## Login
- Open `login.php`
- Log in with a valid admin user
- Try an invalid password and confirm the error is shown
- Confirm logout returns to `login.php`

## Dashboard
- Open `index.php`
- Click each status card and confirm filtering works
- Use the top search and confirm matching orders are shown
- Open an order from the recent orders table

## Orders
- Open `orders.php`
- Test search by order ID, phone, model, and customer name
- Open quick status actions and confirm the menu matches the current status
- Open quick edit modal and confirm fields are populated
- Open a full order page from the list
- Create a new order with an existing customer

## Order Detail
- Open `view_order.php?id=<existing_id>`
- Change status to `Completed`, then to `Collected` on a test order only
- Confirm `Collected` orders no longer offer invalid backward statuses
- Add a part to an order
- Edit and delete a part
- Upload media and delete one file
- Open all print preview buttons
- Update shipping fields and confirm they persist

## Customers
- Open `customers.php`
- Search by customer ID, phone, name, company, ICO
- Click the order-count button and confirm the modal lists orders
- Open a customer from the modal and from the edit button
- Add a private customer and a company customer
- Test ARES lookup for a company

## Inventory
- Open `inventory.php`
- Test filters by text and price range
- Add a new part
- Edit an existing part
- Delete a test part
- Open catalog update, enter a manual catalog URL, and confirm the confirmation dialog appears before submission
- Confirm the last used catalog URL is prefilled on the next open

## Reports
- Open `reports.php`
- Change date range and confirm data updates
- Open technician detail view
- Open modal/list of orders behind report counters if available

## Settings
- Open `settings.php`
- Confirm page loads without hanging
- Save company data
- Open Integrations tab and confirm webhook status block renders
- Save integrations only with valid CSRF/session
- Add, edit, deactivate, and delete a technician on test data only
- Update permissions for a technician
- Change an admin password on a test admin only
- Create a DB backup and confirm the download starts
- Open Updates tab and confirm local version is detected

## Accounting
- Open `accounting.php`
- Create a new invoice
- Edit an invoice
- Change invoice status
- Create a credit note
- Export Pohoda and S3 Money and confirm download starts
- Open A4 and thermal print previews

## Technician Role
- Log in as a technician
- Confirm restricted navigation and page access
- Confirm only allowed orders are visible
- Confirm settings access is limited to the profile-related area
