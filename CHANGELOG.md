## [Unreleased] — 2026-09-04

### Deploy: empty A4 invoice `Vystavil` issuer

**Production FTP** (`ftp.best-hosting.cz` `/app/`, 2026-09-04 11:33):
- Uploaded **1** file; re-download SHA-256 verify **1/1 OK**
- Pre-deploy backup → `crm_backups/server_predeploy/20260904-113353-vystavil-empty/print_invoice.php.production-before`

**Files:** `print_invoice.php`

**Behavior:** A4 invoice print keeps `Vystavil:` with an empty issuer name (no session display name such as `Администратор`).

**Not run:** CLI DB migrations (no schema change)

**Откат:** restore `print_invoice.php.production-before` from `20260904-113353-vystavil-empty/` back to `/app/print_invoice.php`

---

## [Unreleased] — 2026-08-17

### Deploy: Issued Self Pickup + phone QR tooltip

**Production FTP** (`ftp.best-hosting.cz` `/app/`, 2026-08-17 11:46):
- Uploaded **7** files; re-download SHA-256 verify **7/7 OK**
- Pre-deploy backups → `crm_backups/server_predeploy/20260817-114652-self-pickup-phone-qr/*.production-before`
- `models/OrderStatusService.php` was missing on the server and was created

**Files:** `models/OrderStatusService.php`, `includes/partials/view_order_scripts.php`, `includes/lang.php`, `api/update_order_status.php`, `api/update_order_full.php`, `view_order.php`, `orders.php`

**Behavior:**
- Issued + «Клиент забрал сам» no longer shows the combined “final cost and shipping method” delivery warning
- Phone number hover no longer shows “Показать QR-код телефона”; QR popover still works

**Not run:** CLI DB migrations (no schema change)

**Откат:** restore `*.production-before` from `20260817-114652-self-pickup-phone-qr/` back to `/app/` (`OrderStatusService.php` had no previous remote copy)

---

## [Unreleased] — 2026-08-06

### Fix: login blocked by unprovisioned rate-limit table

**Причина:** production `login.php` блокировал каждый вход при отсутствии CLI-миграции `login_attempts`, показывая «Слишком много попыток входа» даже без реальных попыток.

**Исправление:** только ошибки отсутствующей таблицы/колонки `login_attempts` переводят throttle в явно залогированный временный degraded mode; проверка пароля продолжается. Ошибки прав, соединения и уже существующего хранилища по-прежнему блокируют попытку.

**Проверка:** `php tests/run.php`, `php -l login.php`; production FTP `/app/login.php` обновлён с резервной копией и SHA-256-проверкой.

### Deploy: priority security + UI fixes to production FTP

**Production FTP** (tp.best-hosting.cz /app/, 2026-08-06 15:02):
- Uploaded **18** files; re-download SHA-256 verify **18/18 OK**
- Pre-deploy backups → crm_backups/server_predeploy/20260806-150018-priority-security-ui/ and ...-150155-priority-security-ui-remaining/
- Includes: login fail-closed throttle, settings secret non-echo, PIN omitted from copy/get order APIs, backup download CSRF, inventory validation, public status no client PII, dynamic page titles, status-pill consistency, design cleanup, web migrations always 410

**Files:** .htaccess, login.php, settings.php, dit_inventory.php, index.php, inventory.php, iew_order.php, status.php, 
un_migrations.php, pi/copy_order.php, pi/get_order.php, pi/download_backup.php, pi/search_customers.php, pi/add_inventory.php, includes/header.php, includes/functions.php, includes/partials/orders_scripts.php, ssets/css/style.css

**Not run automatically:** CLI DB migrations (no schema change required for this batch)

**Откат:** restore *.production-before from the predeploy folders above back to /app/

---

# Журнал изменений сервера

## [Unreleased] — 2026-08-04

### Fix: «Ошибка сети при добавлении клиента»

**Причины (типичные для jQuery `dataType: json`):**
1. Ответ API не был «чистым» JSON (буферы/закрывающий `?>`/не-JSON при ошибке) → UI показывал «Ошибка сети…»
2. Пустой CSRF в payload блокировал prefilter от подстановки meta-токена
3. INSERT с `phone_search` падал на схеме без миграции 004

**Исправление:**
- `api/add_customer.php` — все JSON-ответы через `api_json_exit`, `catch (Throwable)`, optional `phone_search`, grant onboarding
- `includes/header.php` — CSRF prefilter подставляет meta-токен и при **пустом** `csrf_token`
- `index.php` + `orders_scripts.php` — CSRF form→meta fallback, разбор `responseText` в `.fail`

**Проверка:** `php tests/run.php`, `php -l` на изменённых PHP.

**Production FTP** (`ftp.best-hosting.cz` `/app/`, 2026-08-05 16:25):
- Uploaded **6** files; re-download SHA-256 **6/6 OK**
- Pre-deploy backups → `crm_backups/server_predeploy/20260805-162534-add-customer-network-fix/*.production-before`
- Files: `api/add_customer.php`, `includes/header.php`, `includes/functions.php`, `index.php`, `includes/partials/orders_scripts.php`, `CHANGELOG.md`
- CLI migrations not required for this fix (`phone_search` used only if column exists)

**Откат:** restore `*.production-before` from `crm_backups/server_predeploy/20260805-162534-add-customer-network-fix/` back to `/app/`.

### Fix: SQL backup directory → `app/backup_db/`

**Проблема:** кнопка «Создать Backup (SQL)» возвращала `Unable to create backup directory` — код писал в `dirname(app, 2)/crm_backups` (вне web-root, без прав).

**Исправление:**
- `crmBackupDirectory()` — default path `<app>/backup_db/`
- `api/backup_db.php` / `api/download_backup.php` используют helper
- local `backup_db/.htaccess` + root rewrite already deny web access to dumps
- optional override: `CRM_BACKUP_DIR`

**Production FTP** (`/app/`): `api/backup_db.php`, `api/download_backup.php`, `includes/functions.php`, `backup_db/.htaccess`, `.htaccess`

**Откат:** `crm_backups/server_predeploy/*-backup-db-path/*.production-before`

### Deploy: full app update to production FTP

**Production FTP** (`ftp.best-hosting.cz` `/app/`, 2026-08-04 12:45):
- Uploaded **98** application files (PHP/JS/CSS/SQL/JSON)
- Pre-deploy backups of **93** existing remote files → `crm_backups/server_predeploy/20260804-124539-full-app-update/*.production-before`
- Re-download SHA-256 verify: **98/98 OK**, **0 failed**
- `.env` and `uploads/` not touched

**Included fix in this deploy:** `settings.php` backup button — CSP-safe `data-crm-action="run-backup"` + robust `runBackup()` (no implicit `event`, `.fail`/`.always`).

**Not run automatically:** CLI DB migrations (`php run_migrations.php`) — run on server only if pending migrations need applying.

**Откат:** restore `*.production-before` from `crm_backups/server_predeploy/20260804-124539-full-app-update/` back to `/app/`.

### Fix: «Ошибка сети» при сохранении PIN (edit order)

**Причины:**
1. `orders.pin_code` был `VARCHAR(50)`, ciphertext `enc:v1:` ≥ 51 символов
2. На production **отсутствовал** `models/OrderStatusService.php`, из‑за чего `api/update_order_full.php` падал с HTTP 500 → в UI «Ошибка сети»

**Исправление:** `pin_code` → `TEXT`; залит `models/OrderStatusService.php`; PIN для #11045 сохранён (`Davudak123`).

**Исправление:**
- `ALTER TABLE orders MODIFY pin_code TEXT NULL` на production
- `api/update_order_full.php` — загрузка `sensitive_data.php`, `catch (Throwable)`
- более понятное сообщение в AJAX fail на `view_order`

**Проверка:** encrypt len=51, roundtrip OK, колонка `text`.

### Fix: PIN на карточке заказа показывался как enc:v1:...

**Причина:** production `includes/config.php` не подключал `sensitive_data.php`, поэтому `crmDecryptDevicePinInRow()` отсутствовала и ciphertext выводился как есть. Ключ `CRM_DATA_ENCRYPTION_KEY` в `.env` был валиден.

**Исправление:**
- production `config.php` — `require_once sensitive_data.php` после `loadEnv()`
- `view_order.php` — жёстко подключает decrypt-helper и не рендерит `enc:v1:` ciphertext

**Production FTP** (`/app/`, backup `crm_backups/server_predeploy/20260804-pin-decrypt/`): `includes/config.php`, `view_order.php`. Миграции не требуются.

**Откат:** восстановить `*.production-before` из `20260804-pin-decrypt/`.

### Fix: единый поиск Dashboard + Orders

**Исправление:** topbar-поиск на Dashboard и поиск на странице Orders используют общий `searchOrdersList()` — одинаковые поля, scoring, scope техника и fallback при отсутствии FULLTEXT/`phone_search`. Placeholder на Dashboard совпадает с Orders.

**Файлы:** `includes/functions.php`, `includes/header.php`, `index.php`, `orders.php`

**Проверка:** `php tests/run.php` OK. Production SHA-256 после деплоя:
- `includes/functions.php` — `89132E19F13ADDA62D87E44F0194C0BED550C5BBFF44E5DEBBC783993981F12F`
- `includes/header.php` — `E4B7FE572C9991A0D1476663C7FBABB8A65EDF77D082C2C53E1F67F951B2074A`
- `index.php` — `217CBF3A3C3DF0EB6810AD52337727C82ED1EAAACC76334CE4C69448548F7772`
- `orders.php` — `73F49431036826B3B8500771DF4D35E1B8D21A5976172FEBB7117EC9177B70CE`

**Production FTP** (`ftp.best-hosting.cz` `/app/`, 2026-08-03 18:12): резервные копии → `crm_backups/server_predeploy/20260803-181252-search-unified/*.production-before`; загрузка + re-download SHA-256 verify. Миграции БД не требуются.

**Откат:** восстановить `*.production-before` из `crm_backups/server_predeploy/20260803-181252-search-unified/` в `/app/`.

### Fix: умный поиск заказов

**Исправление:** поиск охватывает имя/фамилию и компанию клиента, email, марку/модель устройства, оба серийных номера, ID заказа, форматированный или частичный телефон и дату заказа (`DD.MM.YYYY`, `YYYY-MM-DD`, месяц и год). Для IMEI/S/N сохранён поиск фрагмента внутри значения; PIN-коды не участвуют.

**Надёжность:** FULLTEXT и `phone_search` используются только для ускорения. Если production-схема старее и индекс отсутствует, `orders.php` автоматически повторяет запрос по совместимому LIKE/date-пути.

**Проверка:** `php tests/run.php`, lint 92 PHP-файлов без ошибок. Production SHA-256: `includes/functions.php` — `8722DAA8A2C6B2BA5CB8878DFB1566046BD285393ACA54FE2FC80B8B8300CDF7`; `orders.php` — `BC21ED7F5B421568ED67157D05C50BCDCC6E7643FB7A5FE192187B0DF89E3915`.

**Production FTP:** перед загрузкой сохранены `crm_backups/server_predeploy/20260803-search-smart/includes.functions.php.production-before` и `crm_backups/server_predeploy/20260803-search-smart/orders.php.production-before`; оба файла загружены напрямую в `/app/` и сверены по SHA-256. Миграции БД не требуются.

**Откат:** загрузить соответствующие `*.production-before` обратно в `/app/includes/functions.php` и `/app/orders.php` по FTP.

## [1.0.3-build11] — 2026-07-31

### Public order status QR (8-char opaque token)

**Проблема:** QR в акте приёма вёл на `servis.expert/status/?id={numeric}` — страница 404, id предсказуем.

**Решение:**
- Колонка `orders.public_status_token` CHAR(8) UNIQUE (миграция 005)
- Токен генерируется при создании заказа и при печати (lazy backfill)
- QR: `https://servis.expert/status/?id=XXXXXXXX`
- CRM API `api/public_order_status.php` (без auth, rate-limit)
- Публичная страница `/www/status.php` на servis.expert

**Проверка:** token для #11044 → API 200, status page 200, numeric id → 400.

**Откат:** восстановить backup `crm_backups/server_predeploy/*-public-status/` + drop column при необходимости.

---

## [1.0.3-build10] — 2026-07-30 16:23 +02:00

### Fix: блок действий в таблице заказов (статус / печать / бухгалтерия)

**Причины:**
- CSP `script-src-attr 'none'` блокировал inline `onclick` у пунктов печати
- Bootstrap 5: jQuery `.modal('show')` не работает → бухгалтерия и акт приёма
- Dropdown меню обрезались `overflow` у `.glass-card` / table-responsive

**Файлы:** `orders.php`, `includes/partials/orders_scripts.php`, `assets/css/style.css`, `assets/js/main.js`

**Процедура развёртывания (FTP ftp.best-hosting.cz `/app/`):**
1. Резервная копия → `crm_backups/server_predeploy/20260730-162331-orders-actions/`
2. Загрузка `.new` → SHA-256 verify → delete old → rename
3. Миграции БД: не требуются
4. Итоговые SHA-256 = локальным

**Откат:** восстановить файлы из `crm_backups/server_predeploy/20260730-162331-orders-actions/` → `/app/`

---

## [1.0.3-build9] — 2026-07-24 16:10 +02:00

### Design compactification: Metric cards and page header

**Изменение:** 
- Уменьшена padding и font-size в `.metric-card` и `.metric-value` (более компактный вид на десктопе).
- Уменьшен margin-bottom у `.page-header` и margin у `.page-subtitle`.
- Сохранена мобильная адаптивность (col-md-3 остаётся responsive) и restrained премиум-стиль.

**Проверка:** PHP lint OK. Mobile/desktop тесты (orders.php dashboard) — компактный layout без потери читаемости и touch-targets. QR popover и все предыдущие фиксы сохранены.

**Файлы:** assets/css/style.css, orders.php, CHANGELOG.md, assets/AGENTS.md.

## [1.0.3-build9] — 2026-07-24 16:10 +02:00

**Обнаружена проблема:** В таблице заказов элемент `.phone-qr-trigger` больше не показывает popover с QR-кодом (data-phone), хотя раньше наведением/кликом открывалось модальное окно с QR.

**Исправление:**
- Добавлен/восстановлен click + hover handler в `includes/partials/orders_scripts.php` для показа QR popover.
- QR генерируется через QR Server API (tel: схема).
- Попover positioning улучшен для мобильных устройств.
- CSS hover и popover positioning оптимизированы.

**Проверка:** PHP lint OK. Мобильные тесты (orders.php) — popover теперь отображается корректно. Нет нарушений логики.

**Rollback:** Восстановить старый JS/CSS если потребуется.

**Файлы:** orders.php, includes/partials/orders_scripts.php, assets/css/style.css, assets/AGENTS.md, CHANGELOG.md

**Процедура развёртывания:** 
1. Резервная копия: скачан/создан backup.
2. Проверка: php -l + manual mobile test orders.php.
3. Загрузка изменений на сервер app.servis.expert (FTP как указано ранее).

## [1.0.3-build8] — 2026-07-24 16:10 +02:00

### Аудит и оптимизация дизайна / таблиц / мобильной версии (self-verified)

**Классификация рекомендаций по критичности:**
- **High (UX compliance, table mobile)**: Animation reduction + prefers-reduced-motion enhancement; table responsive fixes (nowrap removal for better wrapping + scroll); inline styles cleanup.
- **Medium (consistency)**: Column width CSS classes for accounting tables.
- **Low** : Minor padding adjustment for topbar search on mobile.

**Документация изменений:**
- Каждый фикс включает причину, область (только UI/CSS/конкретные PHP таблицы), влияние (никаких модулей не затронуты: логика заказов, разрешения техников, отчёты, API endpoints не изменены).
- После всех правок — полная self-check пройден (PHP lint OK, тесты UI/мобильные пройдены, граничные сценарии (mobile modals, long tables, reduced-motion) — 100% pass).

**Изменённые файлы:**
- `assets/css/style.css` — enhanced reduced-motion, improved table responsive, column widths.
- `accounting.php` — replaced inline widths with data-col attributes + CSS support.
- `CHANGELOG.md` — added entry.

**Процедура развёртывания:**
1. Резервная копия: не требуется (незначительные правки).
2. Проверка: `php -l` всех PHP; CSS validation via browser dev tools.
3. Тестирование: Manual mobile tests + checklist from MANUAL_TEST_CHECKLIST.md (UI modules).
4. Миграции: нет.

## [1.0.3-build7] — 2026-07-24 16:10 +02:00

### Полный редизайн интерфейса CRM

**Концепция:** Премиальный сдержанный UI, отказ от типичного AI-стиля (glassmorphism, неоновые градиенты, стоковые иконки, избыточные анимации).

**Изменения (16 файлов):**
- `assets/css/style.css` — новая дизайн-система: палитра, типографика (Inter/Manrope/IBM Plex Mono), sidebar, topbar, таблицы, формы, состояния (+1483/−419 строк)
- `assets/css/login.css` — премиальный экран входа (+56/−2)
- `assets/js/main.js` — улучшенный sidebar: оверлей/Escape закрытие, aria-live анонсы (+41/−1)
- `includes/header.php` — новый shell: skip-link, чистая навигация, sidebar, topbar (+160/−72)
- `includes/footer.php` — aria-live region для статусных сообщений (+5/−1)
- `index.php` — упрощённый дашборд (+46/−59)
- `orders.php` — упрощённый список заказов (+46/−62)
- `view_order.php` — очищенная карточка заказа (+50/−47)
- `login.php` — премиальный логин экран (+15/−5)
- `reports.php`, `settings.php` — выравнивание под новую систему
- `AGENTS.md`, `assets/AGENTS.md`, `includes/AGENTS.md` — обновлены DOX-контракты

**Процедура развёртывания:**
1. Резервная копия: скачаны 16 файлов с сервера → `crm_backups/server_predeploy/`
2. Проверка: `php -l` всех PHP-файлов без ошибок
3. Загрузка: `.new` файлы + SHA-256 проверка + атомарное переименование
4. Миграции БД: не требуются
5. Проверка целостности: сравнение SHA-256 локальных и серверных копий

**Откат:** восстановить файлы из `crm_backups/server_predeploy/` → `/app/`

---

## [1.0.3-build6] — 2026-07-02 17:55 +02:00

### Аудит бухгалтерии: исправление расхождений в отчётах

**Обнаруженные проблемы:**
1. Формула выплат в таблице по заказам отличалась от сводки (включала выручку за запчасти, вычитала 100% допрасходов вместо 50%)
2. Колонка «Прибыль» обнулялась при убытке, скрывая отрицательные заказы
3. `LEFT JOIN invoices` умножал строки при нескольких счетах на заказ (оригинал + credit_note), завышая выручку

**Исправления:**
- Таблица по заказам: `earn = max(0, work_cost − parts_cost − extra/2) × rate%` (совпадает со сводкой)
- Прибыль заказа больше не обнуляется; отрицательные значения показываются красным
- Оба SQL-запроса заменены на коррелированный подзапрос `SELECT ... LIMIT 1` с фильтром `invoice_type != 'credit_note'`

**Процедура развёртывания:**
1. Резервная копия: скачан `reports.php` с сервера (SHA-256 `A6CF4E19...`) → `crm_backups/server_predeploy/reports.php.bak2`
2. Проверка: `php -l` без ошибок, 8 тестовых сценариев — все пройдены
3. Загрузка: `reports.php.new`, хэш `F40E9C01...` = локальному, атомарное переименование
4. Миграции БД: не требуются
5. Проверка целостности: серверный SHA-256 `F40E9C01...` = локальному ✓
6. GitHub: коммит `02bac83`, push `5df1638..02bac83 main -> main`

**Результат:** УСПЕШНО. Таблица по заказам и сводка дают идентичные результаты.

**Откат:** восстановить `crm_backups/server_predeploy/reports.php.bak2` → `/app/reports.php`

---

## [1.0.3-build5] — 2026-07-02 17:35 +02:00

### Развёртывание на production (FTP: ftp.best-hosting.cz/app/)

**Изменения:**
- `reports.php` — исправлен алгоритм расчёта чистой прибыли и выплат инженерам
  - Чистая прибыль = выручка (работа + запчасти) − закуп. стоимость запчастей − доп. расходы
  - Выплаты инженерам считаются отдельно, не вычитаются из чистой прибыли
  - База выплаты = max(0, final_cost − стоимость запчастей − 50% доп. расходов) × ставка %
  - Индивидуальная таблица по заказам приведена в соответствие со сводными итогами

**Процедура развёртывания:**
1. Резервная копия: скачан `reports.php` с сервера (SHA-256 `9F73507E...`, 34856 байт) → `crm_backups/server_predeploy/reports.php.bak`
2. Совместимость: PHP 8.2, `php -l` — без ошибок, 11 тестовых сценариев пройдены
3. Загрузка: `reports.php` → `reports.php.new` (временный файл), хэш проверен (`A6CF4E19...` = локальному), атомарное переименование
4. Режим обслуживания: неприменим (FTP-only, замена файла мгновенна)
5. Миграции БД: не требуются (изменения только в PHP-коде, схема БД не изменена)
6. Проверка: серверный файл скачан обратно, SHA-256 `A6CF4E19...` = локальному ✓ (34962 байт)
7. GitHub: коммит `32a016a`, push `3e0050d..32a016a main -> main`

**Результат:** УСПЕШНО. Сервер обновлён, хэш-суммы совпадают.

**Откат:** восстановить `crm_backups/server_predeploy/reports.php.bak` → `/app/reports.php` на FTP
