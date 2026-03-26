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
- PHP extensions: pdo, pdo_mysql, mbstring, json, curl, gd

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
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON repair_crm.* TO 'crm_user'@'localhost';
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
DB_USER=crm_user
DB_PASS=your_secure_password

# Telegram Bot (optional)
TG_BOT_TOKEN=

# AI Integration (optional)
AI_API_KEY=
AI_MODEL=google/gemini-2.0-flash-001
AI_PROVIDER=openrouter
```

### 4. Run migrations

Open in browser:
```
https://your-domain.com/run_migrations.php
```

Or via command line:
```bash
php run_migrations.php
```

### 5. Web server configuration

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
    
    location ~ /(backup_db|includes|models|migrations) {
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

- URL: `https://your-domain.com/login.php`
- Username: `admin`
- Password: `admin`

**IMPORTANT:** Change password immediately after first login!

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
│   ├── header.php         # Header template
│   ├── footer.php         # Footer template
│   └── lang.php           # Translations
├── migrations/            # SQL migrations
├── models/                # Data models
├── uploads/               # Uploaded files (created automatically)
├── .env.example           # Configuration template
├── .gitignore             # Git exclusions
├── index.php              # Dashboard
├── login.php              # Authentication
├── orders.php             # Orders
├── customers.php          # Customers
├── inventory.php          # Inventory
├── invoices.php           # Invoices
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
- HTTP Security Headers

## Updates

When updating the system:

```bash
git pull origin main
php run_migrations.php
```

## License

Copyright (c) 2026 Rich Technologies s.r.o. All rights reserved.
