<?php

declare(strict_types=1);

use Catalog2Csv\CatalogReader;
use Catalog2Csv\CsvExporter;
use Catalog2Csv\Products;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__) . '/vendor/autoload.php';

$examples = dirname(__DIR__) . '/examples';
$header = ['Item code', 'Product name', 'Unit price', 'Category', 'Qty', 'Short description', 'Description', 'Pack'];
$products = [
    ['00042', 'A5 Grid Notebook', '4.50', 'Office > Stationery', 24, 'Grid pages for notes and sketches.', 'Soft cover with 80 pages.', 'Each'],
    ['OFF-102', 'Black Ballpoint Pens', '3.10', 'Office > Stationery', 36, 'Pack of four retractable pens.', 'Medium point with black ink.', '4 pack'],
    ['OFF-103', 'Binder Clips, Small', '2.40', 'Office > Stationery', 18, 'Twelve reusable clips.', 'Holds receipts, notes and small stacks of paper.', '12 pack'],
    ['OFF-104', 'Paper Desk Pad', '6.80', 'Office > Stationery', 15, 'A tear-off pad for daily planning.', "Undated pages.\nPrinted on both sides.", 'Each'],
    ['OFF-105', 'Document Tray', '11.95', 'Office > Organization', 0, 'A stackable A4 tray.', 'Open front for easy access.', 'Each'],
    ['OFF-106', 'Label Roll', '5.20', 'Office > Organization', 10, 'Matte writable labels.', 'Simple peel-and-stick labels for general use.', 'Each'],
    ['HOM-201', 'Cotton Dish Cloth', '3.35', 'Home > Kitchen', 40, 'A washable kitchen cloth.', 'Sold in natural white.', 'Each'],
    ['HOM-202', 'Ceramic Mug 350 mL', '8.75', 'Home > Kitchen', 17, 'Plain white glazed ceramic.', 'A classic mug for hot drinks.', 'Each'],
    ['HOM-203', 'Glass Storage Jar', '12.50', 'Home > Kitchen, Home > Storage', 12, 'Clear jar with a fitted lid.', 'Store dry goods on a shelf or in a pantry.', 'Each'],
    ['HOM-204', 'Microfiber Cleaning Cloths', '4.25', 'Home > Cleaning', 28, 'Three soft reusable cloths.', 'For household surfaces and dusting.', '3 pack'],
    ['HOM-205', 'Stackable Storage Basket', '9.60', 'Home > Storage', 8, 'An open basket for small items.', 'Fits standard shelves and closets.', 'Each'],
    ['HOM-206', 'Reusable Shopping Bag', '2.90', 'Home', '', 'A light foldable carry bag.', 'Folds flat for storage.', 'Each'],
];

$csv = fopen($examples . '/supplier-catalog.csv', 'wb');
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, $header, ';', '"', '', "\r\n");
foreach ($products as $product) {
    $product[2] = str_replace('.', ',', $product[2]);
    fputcsv($csv, $product, ';', '"', '', "\r\n");
}
fclose($csv);

$book = new Spreadsheet();
$book->getProperties()
    ->setCreator('Catalog2CSV')
    ->setLastModifiedBy('Catalog2CSV')
    ->setTitle('Sample supplier price list')
    ->setDescription('Fictional demonstration data for Catalog2CSV.');
$sheet = $book->getActiveSheet();
$sheet->setTitle('Supplier catalog');
$sheet->fromArray($header);
$sheet->freezePane('C2');
$sheet->setAutoFilter('A1:H13');
$sheet->getStyle('A1:H1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '125447']],
]);
$sheet->getStyle('C2:C13')->getNumberFormat()->setFormatCode('0.00');
foreach (['A' => 14, 'B' => 30, 'C' => 14, 'D' => 34, 'E' => 11, 'F' => 42, 'G' => 56, 'H' => 13] as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}
foreach ($products as $index => $product) {
    $row = $index + 2;
    $product[2] = (float) $product[2];
    $sheet->fromArray($product, null, 'A' . $row, true);
    $sheet->setCellValueExplicit('A' . $row, $product[0], DataType::TYPE_STRING);
}
(new Xlsx($book))->save($examples . '/supplier-catalog.xlsx');

$job = (new CatalogReader())->read($examples . '/supplier-catalog.xlsx', 'xlsx');
$job['mapping'] = Products::suggest($job['headers']);
$job['decimal'] = '.';
$export = fopen($examples . '/woocommerce-products.csv', 'wb');
CsvExporter::write($export, Products::review($job), $job['mapping']);
fclose($export);

echo "Updated the two supplier inputs and the expected WooCommerce CSV.\n";
