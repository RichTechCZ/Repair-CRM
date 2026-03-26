# Repair CRM

CRM systém pro servisní střediska elektroniky.

## Funkce

- Správa opravárenských zakázek
- Databáze zákazníků
- Evidence náhradních dílů (sklad)
- Generování faktur
- Role-based přístup (administrátor, technik)
- Telegram integrace pro notifikace
- AI integrace pro analýzu
- Vícejazyčná podpora (čeština, ruština)
- Export do účetního systému Pohoda

## Požadavky

- PHP 8.0 nebo novější
- MySQL 5.7+ / MariaDB 10.3+
- Apache s mod_rewrite (nebo Nginx)
- PHP rozšíření: pdo, pdo_mysql, mbstring, json, curl, gd

## Instalace

### 1. Klonování repozitáře

```bash
git clone https://github.com/RichTechCZ/Repair-CRM.git
cd Repair-CRM
```

### 2. Nastavení databáze

Vytvořte MySQL databázi:

```sql
CREATE DATABASE repair_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON repair_crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Konfigurace prostředí

Zkopírujte konfigurační soubor:

```bash
cp .env.example .env
```

Upravte `.env`:

```env
DB_HOST=localhost
DB_NAME=repair_crm
DB_USER=crm_user
DB_PASS=your_secure_password

# Telegram Bot (volitelné)
TG_BOT_TOKEN=

# AI integrace (volitelné)
AI_API_KEY=
AI_MODEL=google/gemini-2.0-flash-001
AI_PROVIDER=openrouter
```

### 4. Spuštění migrací

Otevřete v prohlížeči:
```
https://your-domain.com/run_migrations.php
```

Nebo přes příkazový řádek:
```bash
php run_migrations.php
```

### 5. Konfigurace webového serveru

#### Apache

Ujistěte se, že je povolen `mod_rewrite` a `.htaccess`:

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

    # Odepřít přístup k citlivým souborům
    location ~ /\.(env|git) {
        deny all;
    }
    
    location ~ /(backup_db|includes|models|migrations) {
        deny all;
    }
}
```

### 6. Oprávnění souborů

```bash
chmod 755 uploads/
chmod 755 temp/
```

### 7. První přihlášení

- URL: `https://your-domain.com/login.php`
- Uživatelské jméno: `admin`
- Heslo: `admin`

**DŮLEŽITÉ:** Změňte heslo ihned po prvním přihlášení!

## Struktura projektu

```
Repair-CRM/
├── api/                    # API endpointy
├── assets/
│   ├── css/               # Styly
│   └── js/                # JavaScript
├── includes/
│   ├── config.php         # Konfigurace databáze a relací
│   ├── env_loader.php     # Načítač .env
│   ├── functions.php      # Pomocné funkce
│   ├── header.php         # Hlavička šablony
│   ├── footer.php         # Patička šablony
│   └── lang.php           # Překlady
├── migrations/            # SQL migrace
├── models/                # Datové modely
├── uploads/               # Nahrané soubory (vytvoří se automaticky)
├── .env.example           # Šablona konfigurace
├── .gitignore             # Git vyloučení
├── index.php              # Dashboard
├── login.php              # Autentizace
├── orders.php             # Zakázky
├── customers.php          # Zákazníci
├── inventory.php          # Sklad
├── invoices.php           # Faktury
├── reports.php            # Reporty
├── settings.php           # Nastavení
└── run_migrations.php     # Spouštěč migrací
```

## Bezpečnost

- Soubor `.env` s hesly je **vyloučen** z repozitáře
- Všechna hesla jsou hashována pomocí `password_hash()`
- CSRF ochrana na všech formulářích
- XSS ochrana pomocí escapování výstupu
- Rate limiting pokusů o přihlášení
- HTTP Security Headers

## Aktualizace

Při aktualizaci systému:

```bash
git pull origin main
php run_migrations.php
```

## Licence

Copyright (c) 2026 Rich Technologies s.r.o. Všechna práva vyhrazena.
