# Repair CRM

CRM system for electronics repair service centers.

## Features

- Repair order management
- Customer database
- Inventory tracking (spare parts)
- Invoice generation
- Role-based access (admin, engineer)
- Telegram integration for notifications
- AI integration for analysis
- Multi-language support (Czech, Russian)
- Export to Pohoda accounting system

## Requirements

- PHP 8.0 or higher
- MySQL 5.7+ / MariaDB 10.3+
- Apache with mod_rewrite (or Nginx)
- PHP extensions: pdo, pdo_mysql, mbstring, json, curl, gd, fileinfo, openssl

## Installation

### 1. Clone repository

```bash
git clone https://github.com/RichTechCZ/Repair-CRM.git
cd Repair-CRM
```

### 2. Database setup

Create MySQL database:

```sql
CREATE DATABASE repair_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'repair_crm_app'@'localhost' IDENTIFIED BY '<strong-runtime-password>';
CREATE USER 'repair_crm_migrator'@'localhost' IDENTIFIED BY '<strong-migration-password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON repair_crm.* TO 'repair_crm_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
    ON repair_crm.* TO 'repair_crm_migrator'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Environment configuration

Copy configuration file:

```bash
cp .env.example .env
```

Edit `.env`:

```env
DB_HOST=localhost
DB_NAME=repair_crm
DB_USER=repair_crm_app
DB_PASS=<strong-runtime-password>
DB_MIGRATION_USER=repair_crm_migrator
DB_MIGRATION_PASS=<strong-migration-password>
CRM_ENV=production
# Generate once and keep it stable: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
CRM_DATA_ENCRYPTION_KEY=<base64-encoded-32-byte-key>
# Set to 1 only behind a trusted TLS-terminating reverse proxy.
CRM_TRUST_PROXY_HTTPS=0
# Zone the existing data was written in; pins PHP and the MySQL session for report periods.
CRM_TIMEZONE=Europe/Prague
# Public HTTPS origin for QR codes and customer status links.
CRM_PUBLIC_BASE_URL=https://your-domain.com

# Telegram Bot (optional)
TG_BOT_TOKEN=
# Required for webhook delivery. Use the public HTTPS URL of tg_webhook.php.
TELEGRAM_WEBHOOK_URL=https://crm.example.com/tg_webhook.php
# 1-256 random URL-safe characters; never commit the real value.
TELEGRAM_WEBHOOK_SECRET=

# AI Integration (optional)
AI_API_KEY=
AI_MODEL=google/gemini-2.0-flash-001
AI_PROVIDER=openrouter
```

When Telegram is enabled, sign in as an administrator, open `set_tg.php`, and submit its confirmation form once. The endpoint registers the configured URL and secret with Telegram; `tg_webhook.php` rejects all requests without the matching secret header.

### 4. Run migrations

Production migrations are CLI-only and use the separate migration account:
```bash
php run_migrations.php
```

### 5. Web server configuration

The session cookie is marked `Secure` automatically for direct HTTPS requests. If TLS terminates at a trusted reverse proxy, set `CRM_TRUST_PROXY_HTTPS=1` and make sure the proxy removes any client-supplied `X-Forwarded-Proto` header before setting its own.

#### Apache

Make sure `mod_rewrite` is enabled and `.htaccess` is allowed:

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/Repair-CRM
    
    <Directory /path/to/Repair-CRM>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/Repair-CRM;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\.(env|git) {
        deny all;
    }
    
    # uploads/ holds private order attachments; they are served only by api/media.php.
    location ~ /(backup_db|includes|models|migrations|uploads|tools|tests|temp) {
        deny all;
    }

    location ~ ^/(cron|run_migrations|set_tg|analyze_import|find_missing|parse_new_dump|verify_migration)\.php$ {
        deny all;
    }
}
```

### 6. File permissions

```bash
chmod 755 uploads/
chmod 755 temp/
```

### 7. First login

Create the first admin account from the command line. Set the values only in your shell or server environment, not in Git:

```bash
CRM_ADMIN_USERNAME="<admin-user>" CRM_ADMIN_PASSWORD="<strong-admin-password>" php tools/create_admin.php
```

Then open `https://your-domain.com/login.php` and sign in with the account you created.

### 8. Scheduled housekeeping and monitoring

```bash
# crontab: hourly cleanup of expired rate-limit/login/Telegram rows, old error logs, backup rotation (keeps 14)
0 * * * * cd /path/to/Repair-CRM && php cron.php >> /var/log/repair-crm-cron.log 2>&1
```

`https://your-domain.com/health.php` returns `200 {"status":"ok"}` when the database answers, all migrations are applied and `uploads/` is writable, otherwise `503` with the failing check names. Use it for uptime monitoring and before switching traffic to a new release.

Accounting settings: this business is not a VAT payer, so keep "VAT payer" unchecked; invoice totals equal the order final cost.

## Verification

Run the dependency-free regression suite after changing security or financial rules:

```bash
php tests/run.php
```

With a disposable, migrated database (name ending in `_test`, `_ci` or `_audit`) also run the MySQL integration suite; CI does both on every push:

```bash
php run_migrations.php
CRM_INTEGRATION_DB=1 php tests/integration_mysql.php
```

## Project structure

```
Repair-CRM/
├── api/                    # API endpoints
├── assets/
│   ├── css/               # Styles
│   └── js/                # JavaScript
├── includes/
│   ├── config.php         # Database and session configuration
│   ├── env_loader.php     # .env loader
│   ├── functions.php      # Helper functions
│   ├── migration_runner.php # Idempotent migration orchestration
│   ├── upload_security.php  # Order-attachment validation/storage
│   ├── header.php         # Header template
│   ├── footer.php         # Footer template
│   └── lang.php           # Translations
├── migrations/            # SQL migrations
├── models/                # Data models
├── tests/                 # Dependency-free regression checks
├── uploads/               # Uploaded files (created automatically)
├── .env.example           # Configuration template
├── .gitignore             # Git exclusions
├── index.php              # Dashboard
├── login.php              # Authentication
├── orders.php             # Orders
├── customers.php          # Customers
├── inventory.php          # Inventory
├── accounting.php         # Invoices and accounting
├── reports.php            # Reports
├── settings.php           # Settings
└── run_migrations.php     # Migration runner
```

## Security

- `.env` file with passwords is **excluded** from repository
- All passwords are hashed with `password_hash()`
- CSRF protection on all forms
- XSS protection via output escaping
- Rate limiting on login attempts
- Enforced nonce-based Content Security Policy with no inline event attributes, plus SRI-pinned browser dependencies
- Authenticated encryption for device PINs; keep `CRM_DATA_ENCRYPTION_KEY` stable and outside Git
- Separate least-privilege runtime and migration database accounts
- HTTP security headers

## Updates

The web process cannot update application files. Build and verify an immutable
release outside the live directory, put the CRM into maintenance mode, run
`php run_migrations.php`, activate the release atomically at the hosting layer,
then run a health check. If the health check fails, switch back to the previous
release; database migrations must be written to remain backward-compatible
through that rollback window.

## License

Copyright (c) 2026 Rich Technologies s.r.o. All rights reserved.
