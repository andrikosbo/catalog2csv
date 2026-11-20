<?php

declare(strict_types=1);

use Catalog2Csv\JobStore;
use function Catalog2Csv\configuration;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
try {
    require $root . '/app/bootstrap.php';
    $store = new JobStore(configuration($root)['database']);
    $total = 0;
    do {
        $purged = $store->purge(500);
        $total += $purged;
    } while ($purged === 500);
    echo "Expired jobs removed: {$total}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Catalog2CSV cleanup failed (' . $exception::class . '). Check PHP and local configuration.' . PHP_EOL);
    exit(1);
}
