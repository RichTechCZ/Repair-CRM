<?php
/**
 * Public repair-order status page (no login).
 * QR target: https://app.servis.expert/status.php?id=XXXXXXXX
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/rate_limit.php';

// Public page: do not pull the authenticated shell (header.php redirects guests).
if (function_exists('crmStartContentSecurityPolicy')) {
    // Allow this public entry without blocking on missing nonce in templates below.
}

checkApiRateLimit('public_status_page', 60, 60);

$token = crmNormalizePublicStatusToken($_GET['id'] ?? $_GET['token'] ?? '');
$lang = strtolower(trim((string)($_GET['lang'] ?? ($_SESSION['lang'] ?? 'cs'))));
if (!in_array($lang, ['cs', 'ru', 'en'], true)) {
    $lang = 'cs';
}

$labels = [
    'cs' => [
        'title' => 'Stav opravy',
        'heading' => 'Stav vaší zakázky',
        'token' => 'Kód zakázky',
        'order_no' => 'Číslo zakázky',
        'status' => 'Stav',
        'device' => 'Zařízení',
        'client' => 'Klient',
        'accepted' => 'Přijato',
        'updated' => 'Aktualizováno',
        'not_found' => 'Zakázka s tímto kódem nebyla nalezena.',
        'invalid' => 'Neplatný odkaz. Naskenujte QR kód z předávacího protokolu.',
        'hint' => 'Dotazy: +420 774 008 600 · Vodičkova 791/39, Praha 1',
        'contact' => 'Zavolat servis',
        'home' => 'Servis Expert',
    ],
    'ru' => [
        'title' => 'Статус ремонта',
        'heading' => 'Статус вашего заказа',
        'token' => 'Код заказа',
        'order_no' => 'Номер заказа',
        'status' => 'Статус',
        'device' => 'Устройство',
        'client' => 'Клиент',
        'accepted' => 'Принят',
        'updated' => 'Обновлено',
        'not_found' => 'Заказ с этим кодом не найден.',
        'invalid' => 'Неверная ссылка. Отсканируйте QR-код с акта приёма.',
        'hint' => 'Вопросы: +420 774 008 600 · Vodičkova 791/39, Praha 1',
        'contact' => 'Позвонить в сервис',
        'home' => 'Servis Expert',
    ],
    'en' => [
        'title' => 'Repair status',
        'heading' => 'Your repair status',
        'token' => 'Order code',
        'order_no' => 'Order number',
        'status' => 'Status',
        'device' => 'Device',
        'client' => 'Customer',
        'accepted' => 'Accepted',
        'updated' => 'Updated',
        'not_found' => 'No order was found for this code.',
        'invalid' => 'Invalid link. Scan the QR code from your reception slip.',
        'hint' => 'Contact: +420 774 008 600 · Vodičkova 791/39, Prague 1',
        'contact' => 'Call service',
        'home' => 'Servis Expert',
    ],
];
$L = $labels[$lang] ?? $labels['cs'];

$order = null;
$error = null;
$httpStatus = 200;

if ($token === '') {
    $error = $L['invalid'];
    $httpStatus = 400;
} else {
    $order = crmGetPublicOrderStatusByToken($pdo, $token);
    if ($order === null) {
        $error = $L['not_found'];
        $httpStatus = 404;
    }
}

if ($httpStatus !== 200) {
    http_response_code($httpStatus);
}
if (!headers_sent()) {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

$h = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$fmt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('d.m.Y H:i', $ts);
};

// Prefer CRM localized status when session/lang matches helpers.
if ($order !== null && function_exists('getStatusLabel')) {
    $prevLang = $_SESSION['lang'] ?? null;
    $_SESSION['lang'] = $lang === 'cs' ? 'cs' : ($lang === 'ru' ? 'ru' : 'cs');
    $order['status_label'] = getStatusLabel($order['status'] ?? '');
    if ($prevLang === null) {
        unset($_SESSION['lang']);
    } else {
        $_SESSION['lang'] = $prevLang;
    }
}

$pageTitle = $L['title'] . ($order ? ' #' . (int)$order['order_number'] : '');
$company = trim((string)get_setting('company_name', 'Servis Expert'));
?>
<!DOCTYPE html>
<html lang="<?php echo $h($lang === 'cs' ? 'cs' : $lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo $h($pageTitle); ?> · <?php echo $h($company); ?></title>
    <style>
        :root {
            --bg: #0f1216;
            --card: #171b21;
            --text: #f4efe6;
            --muted: #9aa3ad;
            --line: rgba(255,255,255,0.08);
            --accent: #c99a63;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(201,154,99,0.12), transparent 30%),
                linear-gradient(180deg, #0d0f13 0%, #101318 100%);
            color: var(--text);
            line-height: 1.5;
        }
        .wrap { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem 3rem; }
        .brand {
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .card {
            background: linear-gradient(180deg, rgba(27,31,38,0.98), rgba(22,26,31,0.98));
            border: 1px solid var(--line);
            border-radius: 1rem;
            padding: 1.4rem 1.35rem 1.5rem;
            box-shadow: 0 18px 40px rgba(0,0,0,0.28);
        }
        h1 {
            margin: 0 0 1rem;
            font-size: 1.45rem;
            letter-spacing: -0.02em;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.85rem;
            border-radius: 999px;
            background: rgba(201,154,99,0.16);
            border: 1px solid rgba(201,154,99,0.35);
            color: #f0d2ad;
            font-weight: 750;
            font-size: 0.95rem;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
            margin-top: 1.15rem;
        }
        @media (min-width: 560px) {
            .grid { grid-template-columns: 1fr 1fr; }
        }
        .item {
            border: 1px solid var(--line);
            border-radius: 0.75rem;
            padding: 0.8rem 0.9rem;
            background: rgba(255,255,255,0.02);
        }
        .item .k {
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .item .v {
            font-weight: 700;
            word-break: break-word;
        }
        .error {
            border-left: 3px solid #d35d5d;
            background: rgba(211,93,93,0.1);
            color: #f3c1c1;
            padding: 0.9rem 1rem;
            border-radius: 0.65rem;
            font-weight: 600;
        }
        .hint {
            margin-top: 1.15rem;
            color: var(--muted);
            font-size: 0.92rem;
        }
        .actions {
            margin-top: 1.15rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }
        .actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0.6rem 1rem;
            border-radius: 0.65rem;
            text-decoration: none;
            font-weight: 700;
            color: #101318;
            background: var(--accent);
        }
        .actions a.secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--line);
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand"><?php echo $h($company); ?></div>
        <div class="card">
            <h1><?php echo $h($L['heading']); ?></h1>

            <?php if ($error !== null): ?>
                <div class="error"><?php echo $h($error); ?></div>
            <?php elseif ($order !== null): ?>
                <div class="pill"><?php echo $h((string)($order['status_label'] ?? $order['status'] ?? '—')); ?></div>
                <div class="grid">
                    <div class="item">
                        <span class="k"><?php echo $h($L['token']); ?></span>
                        <span class="v"><?php echo $h((string)($order['public_id'] ?? $token)); ?></span>
                    </div>
                    <div class="item">
                        <span class="k"><?php echo $h($L['order_no']); ?></span>
                        <span class="v">#<?php echo (int)($order['order_number'] ?? 0); ?></span>
                    </div>
                    <div class="item">
                        <span class="k"><?php echo $h($L['device']); ?></span>
                        <span class="v"><?php echo $h((string)($order['device'] ?? '—')); ?></span>
                    </div>
                    <?php if (!empty($order['client_name'])): ?>
                    <div class="item">
                        <span class="k"><?php echo $h($L['client']); ?></span>
                        <span class="v"><?php echo $h((string)$order['client_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="item">
                        <span class="k"><?php echo $h($L['accepted']); ?></span>
                        <span class="v"><?php echo $h($fmt($order['created_at'] ?? null)); ?></span>
                    </div>
                    <div class="item">
                        <span class="k"><?php echo $h($L['updated']); ?></span>
                        <span class="v"><?php echo $h($fmt($order['updated_at'] ?? null)); ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <p class="hint"><?php echo $h($L['hint']); ?></p>
            <div class="actions">
                <a href="tel:+420774008600"><?php echo $h($L['contact']); ?></a>
                <a class="secondary" href="https://servis.expert/"><?php echo $h($L['home']); ?></a>
            </div>
        </div>
    </div>
</body>
</html>
