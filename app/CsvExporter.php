<?php

declare(strict_types=1);

namespace Catalog2Csv;

final class CsvExporter
{
    /** @param resource $stream */
    public static function write($stream, array $review, array $mapping): void
    {
        $included = array_filter($review, static fn (array $item): bool => !$item['excluded']);
        if ($included === [] || array_filter($included, static fn (array $item): bool => $item['errors'] !== []) !== []) {
            throw new CatalogException('Correct or exclude every flagged row and keep at least one product before downloading.');
        }

        $optional = array_filter($mapping, static fn ($column, $field): bool =>
            $column !== null && !Products::FIELDS[$field][1], ARRAY_FILTER_USE_BOTH);
        $headers = ['Type', 'SKU', 'Name', 'Regular price', 'Published'];
        foreach (array_keys($optional) as $field) {
            $headers[] = Products::FIELDS[$field][0];
        }
        if (isset($optional['stock'])) {
            $headers[] = 'In stock?';
        }
        self::line($stream, $headers);
        foreach ($included as $item) {
            $product = $item['fields'];
            $line = ['simple', $product['sku'], $product['name'], $product['price'], '-1'];
            foreach (array_keys($optional) as $field) {
                $line[] = $product[$field];
            }
            if (isset($optional['stock'])) {
                $line[] = $product['stock'] === '0' ? '0' : '1';
            }
            self::line($stream, $line);
        }
    }

    /** @param resource $stream */
    private static function line($stream, array $values): void
    {
        if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Could not write the export.');
        }
    }
}
