# Repair CRM

CRM-система для сервисного центра по ремонту электроники.

## Функции

- Управление заказами на ремонт
- База клиентов
- Учёт запчастей (склад)
- Выставление счетов-фактур
- Ролевая модель (администратор, инженер)
- Telegram-интеграция для уведомлений
- AI-интеграция для анализа
- Мультиязычность (русский, чешский)
- Экспорт в бухгалтерскую систему Pohoda

## Требования

- PHP 8.0 или выше
- MySQL 5.7+ / MariaDB 10.3+
- Apache с mod_rewrite (или Nginx)
- Расширения PHP: pdo, pdo_mysql, mbstring, json, curl, gd

## Установка

### 1. Клонирование репозитория

```bash
git clone https://github.com/RichTechCZ/Repair-CRM.git
cd Repair-CRM
```

### 2. Настройка базы данных

Создайте базу данных MySQL:

```sql
CREATE DATABASE repair_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON repair_crm.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Настройка окружения

Скопируйте файл конфигурации:

```bash
cp .env.example .env
```

Отредактируйте `.env`:

```env
DB_HOST=localhost
DB_NAME=repair_crm
DB_USER=crm_user
DB_PASS=your_secure_password

# Telegram Bot (опционально)
TG_BOT_TOKEN=

# AI Integration (опционально)
AI_API_KEY=
AI_MODEL=google/gemini-2.0-flash-001
AI_PROVIDER=openrouter
```

### 4. Запуск миграций

Откройте в браузере:
```
https://your-domain.com/run_migrations.php
```

Или через командную строку:
```bash
php run_migrations.php
```

### 5. Настройка веб-сервера

#### Apache

Убедитесь что включён `mod_rewrite` и разрешены `.htaccess`:

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

    # Запрет доступа к служебным файлам
    location ~ /\.(env|git) {
        deny all;
    }
    
    location ~ /(backup_db|includes|models|migrations) {
        deny all;
    }
}
```

### 6. Права доступа

```bash
chmod 755 uploads/
chmod 755 temp/
```

### 7. Первый вход

- URL: `https://your-domain.com/login.php`
- Логин: `admin`
- Пароль: `admin`

**ВАЖНО:** Смените пароль сразу после первого входа!

## Структура проекта

```
Repair-CRM/
├── api/                    # API endpoints
├── assets/
│   ├── css/               # Стили
│   └── js/                # JavaScript
├── includes/
│   ├── config.php         # Конфигурация БД и сессий
│   ├── env_loader.php     # Загрузчик .env
│   ├── functions.php      # Вспомогательные функции
│   ├── header.php         # Шапка
│   ├── footer.php         # Подвал
│   └── lang.php           # Переводы
├── migrations/            # SQL миграции
├── models/                # Модели данных
├── uploads/               # Загруженные файлы (создаётся автоматически)
├── .env.example           # Шаблон конфигурации
├── .gitignore             # Исключения Git
├── index.php              # Главная (дашборд)
├── login.php              # Авторизация
├── orders.php             # Заказы
├── customers.php          # Клиенты
├── inventory.php          # Склад
├── invoices.php           # Счета
├── reports.php            # Отчёты
├── settings.php           # Настройки
└── run_migrations.php     # Запуск миграций
```

## Безопасность

- `.env` файл с паролями **не попадает** в репозиторий
- Все пароли хешируются через `password_hash()`
- CSRF-защита на всех формах
- XSS-защита через экранирование вывода
- Rate limiting на попытки входа
- HTTP Security Headers

## Обновления

При обновлении системы:

```bash
git pull origin main
php run_migrations.php
```

## Лицензия

Copyright (c) 2026 Rich Technologies s.r.o. Все права защищены.
