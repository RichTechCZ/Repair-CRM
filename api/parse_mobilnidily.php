<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(__('unauthorized'));
}

set_time_limit(300); // 5 minutes

$base_url = "https://www.mobilnidily.cz";
$start_url = $base_url . "/nahradni-dily-apple-iphone/";

function fetchHtml($url) {
    sleep(2); // Rate-limiting protection
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

$html = fetchHtml($start_url);
$doc = new DOMDocument();
@$doc->loadHTML($html);
$xpath = new DOMXPath($doc);

// Find subcategory links (only those under iPhone)
$links = $xpath->query("//ul[contains(@class, 'category-list')]//a | //div[contains(@class, 'categories')]//a");
$subcategories = [];
foreach ($links as $link) {
    $href = $link->getAttribute('href');
    $name = trim($link->nodeValue);
    if (strpos($href, 'nahradni-dily-iphone') !== false || strpos($href, 'iphone') !== false) {
        $full_url = (strpos($href, 'http') === 0) ? $href : $base_url . $href;
        $subcategories[$full_url] = $name;
    }
}

// If automatic link finding fails, use manual list of major ones
if (count($subcategories) < 5) {
    $subcategories = [
        $base_url . "/nahradni-dily-iphone-13-pro/" => "iPhone 13 Pro",
        $base_url . "/nahradni-dily-iphone-13/" => "iPhone 13",
        $base_url . "/nahradni-dily-iphone-12-pro/" => "iPhone 12 Pro",
        $base_url . "/nahradni-dily-iphone-12/" => "iPhone 12",
        $base_url . "/nahradni-dily-iphone-11-pro/" => "iPhone 11 Pro",
        $base_url . "/nahradni-dily-iphone-11/" => "iPhone 11",
        $base_url . "/nahradni-dily-iphone-xs/" => "iPhone XS",
        $base_url . "/nahradni-dily-iphone-x/" => "iPhone X"
    ];
}

$added_count = 0;
$updated_count = 0;

foreach ($subcategories as $url => $model_name) {
    // Only process Apple iPhone models (clean name)
    $clean_model = str_replace(['Náhradní díly', 'pro', 'servis'], '', $model_name);
    $clean_model = trim($clean_model);
    
    $cat_html = fetchHtml($url);
    $cat_doc = new DOMDocument();
    @$cat_doc->loadHTML($cat_html);
    $cat_xpath = new DOMXPath($cat_doc);
    
    $products = $cat_xpath->query("//div[contains(@class, 'product')]");
    
    foreach ($products as $product) {
        $p_xpath = new DOMXPath($cat_doc);
        
        // Extract Name
        $name_node = $cat_xpath->query(".//span[@data-micro='name']", $product)->item(0);
        $name = $name_node ? trim($name_node->nodeValue) : '';
        if (empty($name)) continue;
        
        // Extract SKU
        $sku_node = $cat_xpath->query(".//span[@data-micro='sku']", $product)->item(0);
        $sku = $sku_node ? trim($sku_node->nodeValue) : '';
        
        // Extract Price
        $price_node = $cat_xpath->query(".//div[@data-micro='offer']/@data-micro-price", $product)->item(0);
        $price = $price_node ? floatval($price_node->nodeValue) : 0;
        
        // Extract Image
        $img_node = $cat_xpath->query(".//img/@data-micro-image | .//img/@src", $product)->item(0);
        $img_url = $img_node ? $img_node->nodeValue : '';
        if (strpos($img_url, 'data:image') === 0) {
            // Try next image attribute if lazy loaded
            $img_node = $cat_xpath->query(".//img/@data-src", $product)->item(0);
            if ($img_node) $img_url = $img_node->nodeValue;
        }

        // Upsert into database
        $stmt = $pdo->prepare("SELECT id FROM inventory WHERE sku = ?");
        $stmt->execute([$sku]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $upd = $pdo->prepare("UPDATE inventory SET 
                sale_price = ?, 
                image_path = ?
                WHERE id = ?");
            $upd->execute([$price, $img_url, $existing['id']]);
            $updated_count++;
        } else {
            $ins = $pdo->prepare("INSERT INTO inventory (part_name, sku, sale_price, cost_price, quantity, min_stock, image_path) 
                VALUES (?, ?, ?, ?, 0, 5, ?)");
            $ins->execute([$name, $sku, $price, $price * 0.7, $img_url]);
            $added_count++;
        }
    }
    
    // limit to 5 categories for now to prevent long waits
    if ($added_count + $updated_count > 100) break;
}

header("Location: ../inventory.php?parsed=1&added=$added_count&updated=$updated_count");
