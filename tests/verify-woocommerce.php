<?php

declare(strict_types=1);

// Usage: php tests/verify-woocommerce.php /path/to/test-wordpress /path/to/download.csv
if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_file($argv[1] . '/wp-load.php') || !is_readable($argv[2])) {
    fwrite(STDERR, "Usage: php tests/verify-woocommerce.php /path/to/test-wordpress /path/to/download.csv\n");
    exit(1);
}

require $argv[1] . '/wp-load.php';

if (!function_exists('wc_get_product_id_by_sku')) {
    fwrite(STDERR, "Activate WooCommerce on the test installation first.\n");
    exit(1);
}

$input = fopen($argv[2], 'rb');
$header = fgetcsv($input, 0, ',', '"', '');
if (!$header || array_diff(['Type', 'SKU', 'Name', 'Regular price', 'Published'], $header) !== []) {
    fwrite(STDERR, "The input is missing Catalog2CSV export columns.\n");
    exit(1);
}

$checked = 0;
$failures = [];
while (($row = fgetcsv($input, 0, ',', '"', '')) !== false) {
    if (count($row) !== count($header)) {
        $failures[] = 'Malformed CSV row.';
        continue;
    }
    $data = array_combine($header, $row);
    $sku = $data['SKU'];
    $product = wc_get_product(wc_get_product_id_by_sku($sku));
    if (!$product instanceof WC_Product) {
        $failures[] = "{$sku} is missing.";
        continue;
    }

    $expected = [
        'sku' => $sku,
        'name' => $data['Name'],
        'regular price' => $data['Regular price'],
        'status' => 'draft',
        'type' => 'simple',
    ];
    $actual = [
        'sku' => $product->get_sku('edit'),
        'name' => $product->get_name('edit'),
        'regular price' => $product->get_regular_price('edit'),
        'status' => $product->get_status('edit'),
        'type' => $product->get_type(),
    ];

    if (isset($data['Stock'])) {
        $expected['stock quantity'] = $data['Stock'];
        $expected['manage stock'] = $data['Stock'] !== '';
        $expected['stock status'] = $data['Stock'] === '0' ? 'outofstock' : 'instock';
        $actual['stock quantity'] = (string) $product->get_stock_quantity('edit');
        $actual['manage stock'] = $product->get_manage_stock('edit');
        $actual['stock status'] = $product->get_stock_status('edit');
    }
    foreach (['Short description' => 'get_short_description', 'Description' => 'get_description'] as $field => $getter) {
        if (isset($data[$field])) {
            $expected[$field] = str_replace(["\r\n", "\r"], "\n", $data[$field]);
            $actual[$field] = str_replace(["\r\n", "\r"], "\n", $product->$getter('edit'));
        }
    }
    if (!empty($data['Categories'])) {
        $paths = [];
        foreach ($product->get_category_ids('edit') as $id) {
            $levels = [];
            foreach (array_reverse(get_ancestors($id, 'product_cat')) as $parent) {
                $levels[] = get_term($parent, 'product_cat')->name;
            }
            $levels[] = get_term($id, 'product_cat')->name;
            $paths[] = implode(' > ', $levels);
        }
        $categories = array_map(static fn ($category) => implode(' > ', array_map('trim', explode('>', $category))), explode(',', $data['Categories']));
        sort($categories);
        sort($paths);
        $expected['category paths'] = $categories;
        $actual['category paths'] = $paths;
    }
    foreach ($expected as $field => $value) {
        if ($actual[$field] !== $value) {
            $failures[] = "{$sku}: {$field} differs from the expected import.";
        }
    }
    $checked++;
}
fclose($input);

if ($failures !== [] || $checked === 0) {
    fwrite(STDERR, implode(PHP_EOL, array_merge(["Checked {$checked} products."], $failures)) . PHP_EOL);
    exit(1);
}
printf("Verified %d products in WordPress %s / WooCommerce %s.\n", $checked, get_bloginfo('version'), WC_VERSION);
