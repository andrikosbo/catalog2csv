<?php

declare(strict_types=1);

ini_set('display_errors', '0');

$mount = __DIR__ . '/mount.local.php';
$root = is_file($mount) ? require $mount : dirname(__DIR__);

header('Cache-Control: no-store');
header('Content-Security-Policy: default-src \'none\'; style-src \'self\'; img-src \'self\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (!is_string($root) || !is_file($root . '/app/bootstrap.php')) {
    http_response_code(503);
    exit('Catalog2CSV setup is incomplete. See the deployment guide.');
}

try {
    require $root . '/app/bootstrap.php';
    $config = \Catalog2Csv\configuration($root);
    \Catalog2Csv\openSession($config);
    (new \Catalog2Csv\Application($root, $config))->run();
} catch (Throwable $exception) {
    error_log('Catalog2CSV request failed (' . $exception::class . ', code ' . $exception->getCode() . ').');
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Catalog2CSV is temporarily unavailable. Check the installation, PHP error log, and database connection.');
    }
}
