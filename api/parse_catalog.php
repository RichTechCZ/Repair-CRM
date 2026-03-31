<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(__('unauthorized'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die(__('csrf_token_invalid'));
}

set_time_limit(300);

function redirectToInventory(array $params = []): void {
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: ../inventory.php' . $query);
    exit;
}

function redirectCatalogError(string $errorKey): void {
    redirectToInventory(['catalog_error' => $errorKey]);
}

function isPublicCatalogUrl(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || strpos($host, '.') === false) {
        return false;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && !in_array($port, [80, 443], true)) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    $resolvedIps = [];
    $ipv4Records = @gethostbynamel($host);
    if (is_array($ipv4Records)) {
        $resolvedIps = array_merge($resolvedIps, $ipv4Records);
    }

    if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }
    }

    foreach (array_unique($resolvedIps) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }

    return true;
}

function getCatalogOrigin(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }

    return $origin;
}

function normalizePath(string $path): string {
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
}

function resolveCatalogUrl(string $origin, string $currentUrl, string $candidate): string {
    $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($candidate === '' || str_starts_with($candidate, '#') || preg_match('/^(javascript|mailto|tel):/i', $candidate)) {
        return '';
    }

    if (preg_match('#^https?://#i', $candidate)) {
        return preg_replace('/#.*$/', '', $candidate);
    }

    $currentParts = parse_url($currentUrl);
    $scheme = (string)($currentParts['scheme'] ?? 'https');

    if (str_starts_with($candidate, '//')) {
        return $scheme . ':' . $candidate;
    }

    $relativeParts = parse_url($candidate);
    $relativePath = (string)($relativeParts['path'] ?? '');
    $relativeQuery = isset($relativeParts['query']) ? ('?' . $relativeParts['query']) : '';

    if (str_starts_with($candidate, '/')) {
        return rtrim($origin, '/') . normalizePath($relativePath) . $relativeQuery;
    }

    $currentPath = (string)($currentParts['path'] ?? '/');
    $currentDir = preg_replace('#/[^/]*$#', '/', $currentPath);
    $fullPath = normalizePath($currentDir . $relativePath);

    return rtrim($origin, '/') . $fullPath . $relativeQuery;
}

function fetchHtml(string $url): string {
    sleep(1);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $html = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if (!is_string($html) || $html === '' || $httpCode < 200 || $httpCode >= 400) {
        return '';
    }

    return $html;
}

function createXPathFromHtml(string $html): ?DOMXPath {
    if ($html === '') {
        return null;
    }

    $previousErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = @$doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    if (!$loaded) {
        return null;
    }

    return new DOMXPath($doc);
}

function collectCategoryUrls(DOMXPath $xpath, string $currentUrl, string $origin): array {
    $links = $xpath->query("//ul[contains(@class, 'category-list')]//a[@href] | //div[contains(@class, 'categories')]//a[@href]");
    $categoryUrls = [];
    $originHost = (string)parse_url($origin, PHP_URL_HOST);

    foreach ($links as $link) {
        $resolvedUrl = resolveCatalogUrl($origin, $currentUrl, $link->getAttribute('href'));
        if ($resolvedUrl === '') {
            continue;
        }

        $host = (string)parse_url($resolvedUrl, PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $originHost) !== 0) {
            continue;
        }

        $categoryUrls[$resolvedUrl] = trim($link->nodeValue);
    }

    return $categoryUrls;
}

function queryFirstValue(DOMXPath $xpath, DOMNode $contextNode, array $queries): string {
    foreach ($queries as $query) {
        $node = $xpath->query($query, $contextNode)->item(0);
        if ($node instanceof DOMNode) {
            $value = trim((string)$node->nodeValue);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function parseMoneyValue(string $rawValue): float {
    $value = html_entity_decode(strip_tags($rawValue), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[^\d,.\-]/u', '', $value);
    $value = is_string($value) ? trim($value) : '';

    if ($value === '' || $value === '-') {
        return 0.0;
    }

    $lastComma = strrpos($value, ',');
    $lastDot = strrpos($value, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($lastComma !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function upsertInventoryItem(string $name, string $sku, float $price, string $imageUrl): string {
    global $pdo;

    $lookupBySku = $sku !== '';
    if ($lookupBySku) {
        $stmt = $pdo->prepare("SELECT id, sale_price, image_path FROM inventory WHERE sku = ? LIMIT 1");
        $stmt->execute([$sku]);
    } else {
        $stmt = $pdo->prepare("SELECT id, sale_price, image_path FROM inventory WHERE part_name = ? AND (sku IS NULL OR sku = '') LIMIT 1");
        $stmt->execute([$name]);
    }

    $existing = $stmt->fetch();

    if ($existing) {
        $newPrice = $price > 0 ? $price : (float)$existing['sale_price'];
        $newImageUrl = $imageUrl !== '' ? $imageUrl : (string)($existing['image_path'] ?? '');
        $update = $pdo->prepare("UPDATE inventory SET sale_price = ?, image_path = ? WHERE id = ?");
        $update->execute([$newPrice, $newImageUrl, $existing['id']]);
        return 'updated';
    }

    $insert = $pdo->prepare("INSERT INTO inventory (part_name, sku, sale_price, cost_price, quantity, min_stock, image_path) VALUES (?, ?, ?, ?, 0, 5, ?)");
    $insert->execute([$name, $lookupBySku ? $sku : null, $price, $price > 0 ? $price * 0.7 : 0, $imageUrl]);
    return 'added';
}

$catalogUrl = trim((string)($_POST['catalog_url'] ?? ''));
if (!isPublicCatalogUrl($catalogUrl)) {
    redirectCatalogError('invalid_url');
}

$origin = getCatalogOrigin($catalogUrl);
if ($origin === '') {
    redirectCatalogError('invalid_url');
}

set_setting('inventory_catalog_url', $catalogUrl);

try {
    $startHtml = fetchHtml($catalogUrl);
    if ($startHtml === '') {
        redirectCatalogError('fetch_failed');
    }

    $startXPath = createXPathFromHtml($startHtml);
    if (!$startXPath instanceof DOMXPath) {
        redirectCatalogError('processing_failed');
    }

    $categoryUrls = collectCategoryUrls($startXPath, $catalogUrl, $origin);
    if (empty($categoryUrls)) {
        $categoryUrls = [$catalogUrl => ''];
    }

    $addedCount = 0;
    $updatedCount = 0;
    $processedPages = [];
    $maxPages = 25;
    $maxProducts = 300;

    foreach (array_keys($categoryUrls) as $pageUrl) {
        if (isset($processedPages[$pageUrl])) {
            continue;
        }
        $processedPages[$pageUrl] = true;

        if (count($processedPages) > $maxPages) {
            break;
        }

        $pageHtml = $pageUrl === $catalogUrl ? $startHtml : fetchHtml($pageUrl);
        if ($pageHtml === '') {
            continue;
        }

        $pageXPath = createXPathFromHtml($pageHtml);
        if (!$pageXPath instanceof DOMXPath) {
            continue;
        }

        $products = $pageXPath->query("//div[contains(@class, 'product') and (.//*[@data-micro='name'] or .//span[@data-micro='name'])]");
        foreach ($products as $product) {
            $name = queryFirstValue($pageXPath, $product, [
                ".//span[@data-micro='name']",
                ".//*[contains(@class, 'product-name')]//a",
                ".//*[contains(@class, 'name')]//a",
            ]);
            if ($name === '') {
                continue;
            }

            $sku = queryFirstValue($pageXPath, $product, [
                ".//span[@data-micro='sku']",
                ".//*[contains(@class, 'sku')]",
            ]);

            $priceRaw = queryFirstValue($pageXPath, $product, [
                ".//div[@data-micro='offer']/@data-micro-price",
                ".//*[@data-micro='price']/@content",
                ".//*[@itemprop='price']/@content",
                ".//*[contains(@class, 'price-final')]",
            ]);
            $price = parseMoneyValue($priceRaw);

            $imageUrl = queryFirstValue($pageXPath, $product, [
                ".//img/@data-micro-image",
                ".//img/@data-src",
                ".//img/@src",
            ]);
            if (strpos($imageUrl, 'data:image') === 0) {
                $imageUrl = '';
            } elseif ($imageUrl !== '') {
                $imageUrl = resolveCatalogUrl($origin, $pageUrl, $imageUrl);
            }

            $result = upsertInventoryItem($name, $sku, $price, $imageUrl);
            if ($result === 'added') {
                $addedCount++;
            } else {
                $updatedCount++;
            }

            if (($addedCount + $updatedCount) >= $maxProducts) {
                break 2;
            }
        }
    }

    if (($addedCount + $updatedCount) === 0) {
        redirectCatalogError('no_products');
    }

    redirectToInventory([
        'catalog_imported' => 1,
        'catalog_added' => $addedCount,
        'catalog_updated' => $updatedCount,
    ]);
} catch (Throwable $e) {
    log_error('Catalog import failed', 'inventory_import', $e->getMessage());
    redirectCatalogError('processing_failed');
}
