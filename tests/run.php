<?php

declare(strict_types=1);

use Catalog2Csv\CatalogException;
use Catalog2Csv\CatalogReader;
use Catalog2Csv\CsvExporter;
use Catalog2Csv\Products;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__) . '/vendor/autoload.php';

function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function reject(callable $callback, string $fragment): void
{
    try {
        $callback();
    } catch (CatalogException $exception) {
        if (!str_contains($exception->getMessage(), $fragment)) {
            throw new RuntimeException('Unexpected validation error: ' . $exception->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected the catalog to be rejected.');
}

function contents(string $bytes, callable $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'catalog2csv-test-');
    try {
        file_put_contents($path, $bytes);
        return $callback($path);
    } finally {
        unlink($path);
    }
}

function export(array $job): string
{
    $stream = fopen('php://temp', 'w+b');
    try {
        CsvExporter::write($stream, Products::review($job), $job['mapping']);
        rewind($stream);
        return stream_get_contents($stream);
    } finally {
        fclose($stream);
    }
}

$reader = new CatalogReader();
$examples = dirname(__DIR__) . '/examples';
$tests = [];

$tests['bundled XLSX and semicolon/decimal-comma CSV produce the reference output'] = static function () use ($reader, $examples): void {
    foreach (['xlsx' => '.', 'csv' => ','] as $extension => $mark) {
        $job = $reader->read($examples . '/supplier-catalog.' . $extension, $extension);
        same(12, count($job['rows']));
        $job['mapping'] = Products::suggest($job['headers']);
        $job['decimal'] = $mark;
        same(file_get_contents($examples . '/woocommerce-products.csv'), export($job));
        same('00042', Products::review($job)[2]['fields']['sku']);
    }
};

$tests['public XLSX converts all 100 rows with accurate headers'] = static function () use ($reader, $examples): void {
    $path = $examples . '/public/databrighten-inventory-100.xlsx';
    same('b65c7019804b980d4e22e44ca021ad0f0bbd8ef26a5ffe99d3b7968ed900adfa', hash_file('sha256', $path));
    $job = $reader->read($path, 'xlsx');
    $job['mapping'] = Products::suggest($job['headers']);
    $job['decimal'] = '.';
    same(100, count($job['rows']));
    same(['sku' => 0, 'name' => 1, 'price' => 8, 'categories' => 2, 'stock' => 4, 'short_description' => null, 'description' => null], $job['mapping']);
    same(strlen(export($job)) > 4000, true);
    $first = Products::review($job)[2]['fields'];
    same(['sku' => 'P0001-WH1', 'name' => 'Mesh Office Chair', 'price' => '288.97', 'categories' => 'Furniture', 'stock' => '267'], $first);
};

$tests['Greek UTF-8, BOM, tab delimiter, embedded quotes, and multiline source numbers'] = static function () use ($reader): void {
    $sample = "\xEF\xBB\xBFΚωδικός\tΟνομασία\tΤιμή\tΠεριγραφή\r\n001\tΜπλοκ\t12,50\t\"Line 1\nLine \"\"2\"\"\"\r\n\r\n02\tΚούπα\t8\tΤεστ";
    $job = contents($sample, fn ($path) => $reader->read($path, 'csv'));
    same([2, 5], array_keys($job['rows']));
    same("Line 1\nLine \"2\"", $job['rows'][2][3]);
    $job['mapping'] = Products::suggest($job['headers']);
    $job['decimal'] = ',';
    same('12.50', Products::review($job)[2]['fields']['price']);
    same('001', Products::review($job)[2]['fields']['sku']);
    same(true, str_contains(export($job), 'Κούπα'));
};

$tests['reject malformed headers, malformed records, invalid UTF-8 and oversized rows'] = static function () use ($reader): void {
    foreach ([
        ["SKU,Name,Price\nabc,\"broken,2", 'unclosed'],
        ["SKU,Name,Price\nabc,bad\"name,2", 'unquoted'],
        ["SKU,Name,Price\nabc,\"book\" trailing,2", 'closing quote'],
        ["SKU,Name,Price\nabc,Book", 'different number'],
        ["SKU,Name,Price\nabc,\xFF,2", 'UTF-8'],
        ["SKU,Name,Price\nabc," . str_repeat('a', 8193) . ",2", '8 KiB'],
        ["SKU,Name,Price\n" . str_repeat("a,A,2\n", 5001), '5,000'],
        ["SKU,Name,Price\n" . str_repeat('A', CatalogReader::MAX_BYTES), '5 MiB'],
    ] as [$sample, $error]) {
        reject(fn () => contents($sample, fn ($path) => $reader->read($path, 'csv')), $error);
    }
};

$tests['mapping rejects reused or nonexistent source columns'] = static function (): void {
    reject(fn () => Products::mapping(['sku' => '0', 'name' => '0'], ['SKU', 'Name', 'Price']), 'only one');
    reject(fn () => Products::mapping(['sku' => '9'], ['SKU', 'Name', 'Price']), 'SKU');
};

$tests['validation blocks invalid prices, formulas, blanks, duplicates and markup'] = static function () use ($reader): void {
    $job = contents("SKU,Name,Price\nAbc,Notebook,1.25\nabc,Notebook,1.30\nX,,12.123\nY,=4+4,3", fn ($path) => $reader->read($path, 'csv'));
    $job['mapping'] = Products::suggest($job['headers']);
    $job['decimal'] = '.';
    $items = Products::review($job);
    same('Duplicate SKU in the included rows.', $items[2]['errors']['sku']);
    same('Duplicate SKU in the included rows.', $items[3]['errors']['sku']);
    same(['name', 'price'], array_keys($items[4]['errors']));
    same(['name'], array_keys($items[5]['errors']));
    reject(fn () => export($job), 'Correct or exclude');
    $job['excluded'] = [3 => true, 5 => true];
    $job['edits'] = [4 => ['sku' => 'X', 'name' => 'Cotton napkins', 'price' => '12.30']];
    $csv = export($job);
    same(true, str_contains($csv, 'simple,X,"Cotton napkins",12.30,-1'));
    same(false, str_contains($csv, '=4+4'));
    same(['description'], array_keys(Products::validate(['description' => '<p>Hi</p>'], '.')[1]));
    same(['description'], array_keys(Products::validate(['description' => "\u{FEFF}＋5"], '.')[1]));
};

$tests['XLSX first sheet only, formula stays flagged, and coordinates are bounded'] = static function () use ($reader): void {
    contents('', static function (string $path) use ($reader): void {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([['SKU', 'Name', 'Price'], ['ABC', 'Notebook', '=2+2']]);
        $book->createSheet()->setTitle('Other')->setCellValue('A1', 'Ignored');
        $book->setActiveSheetIndex(1);
        $writer = new Xlsx($book);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $job = $reader->read($path, 'xlsx');
        same(null, $job['rows'][2][2]);
        $job['mapping'] = Products::suggest($job['headers']);
        $job['decimal'] = '.';
        reject(fn () => export($job), 'Correct or exclude');
        $book->getSheet(0)->setCellValue('AG2', 'Too wide');
        $writer->save($path);
        reject(fn () => $reader->read($path, 'xlsx'), '32 columns');
    });
    reject(fn () => contents('not a workbook', fn ($path) => $reader->read($path, 'xlsx')), 'archive');
};

$failed = 0;
foreach ($tests as $title => $test) {
    try {
        $test();
        echo "PASS {$title}\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL {$title}: {$exception->getMessage()}\n");
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
