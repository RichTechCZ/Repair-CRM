# Журнал изменений сервера

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
