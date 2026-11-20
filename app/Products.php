<?php

declare(strict_types=1);

namespace Catalog2Csv;

final class Products
{
    public const FIELDS = [
        'sku' => ['SKU', true, ['sku', 'code', 'item code', 'product code', 'κωδικός', 'κωδικος']],
        'name' => ['Name', true, ['name', 'product', 'product name', 'title', 'ονομασία', 'προϊόν']],
        'price' => ['Regular price', true, ['regular price', 'price', 'unit price', 'retail price', 'τιμή', 'τιμη', 'λιανική']],
        'categories' => ['Categories', false, ['categories', 'category', 'κατηγορία', 'κατηγορια']],
        'stock' => ['Stock', false, ['stock', 'qty', 'quantity', 'current stock', 'quantity on hand', 'απόθεμα', 'ποσότητα']],
        'short_description' => ['Short description', false, ['short description', 'σύντομη περιγραφή']],
        'description' => ['Description', false, ['description', 'long description', 'full description', 'αναλυτική περιγραφή', 'περιγραφή']],
    ];

    public static function suggest(array $headers): array
    {
        $normalized = array_map(static function (string $header): string {
            return mb_strtolower(preg_replace('/[\s_-]+/u', ' ', $header));
        }, $headers);
        $result = [];
        foreach (self::FIELDS as $field => [, , $aliases]) {
            $columns = array_keys(array_intersect($normalized, $aliases));
            $result[$field] = count($columns) === 1 && !in_array($columns[0], $result, true) ? $columns[0] : null;
        }
        return $result;
    }

    public static function mapping(mixed $submitted, array $headers): array
    {
        $mapping = [];
        foreach (self::FIELDS as $field => [$label, $required]) {
            $column = is_array($submitted) ? ($submitted[$field] ?? '') : '';
            if (!$required && $column === '') {
                $mapping[$field] = null;
                continue;
            }
            if (!is_string($column) || !ctype_digit($column) || !array_key_exists((int) $column, $headers)) {
                throw new CatalogException("Choose a source column for {$label}.");
            }
            $column = (int) $column;
            if (in_array($column, $mapping, true)) {
                throw new CatalogException('Use each source column for only one WooCommerce field.');
            }
            $mapping[$field] = $column;
        }
        return $mapping;
    }

    public static function values(array $job, int $row): array
    {
        if (isset($job['edits'][$row])) {
            return $job['edits'][$row];
        }
        $values = [];
        foreach ($job['mapping'] as $field => $column) {
            if ($column !== null) {
                $values[$field] = $job['rows'][$row][$column];
            }
        }
        return $values;
    }

    public static function review(array $job): array
    {
        $result = [];
        $skuRows = [];
        foreach ($job['rows'] as $row => $_) {
            $excluded = isset($job['excluded'][$row]);
            [$fields, $errors] = self::validate(self::values($job, $row), $job['decimal']);
            $result[$row] = ['fields' => $fields, 'errors' => $errors, 'excluded' => $excluded];
            if (!$excluded && !isset($errors['sku'])) {
                $skuRows[strtolower($fields['sku'])][] = $row;
            }
        }
        foreach ($skuRows as $duplicates) {
            if (count($duplicates) > 1) {
                foreach ($duplicates as $row) {
                    $result[$row]['errors']['sku'] = 'Duplicate SKU in the included rows.';
                }
            }
        }
        return $result;
    }

    public static function validate(array $values, string $decimal): array
    {
        $clean = [];
        $errors = [];
        foreach ($values as $field => $value) {
            try {
                $clean[$field] = self::normalize($field, $value, $decimal);
            } catch (CatalogException $exception) {
                $errors[$field] = $exception->getMessage();
                $clean[$field] = $value === null ? '' : trim((string) $value);
            }
        }
        return [$clean, $errors];
    }

    private static function normalize(string $field, string|float|int|null $value, string $decimal): string
    {
        if ($value === null) {
            throw new CatalogException('Replace the spreadsheet formula, error, or unsupported cell with a plain value.');
        }
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
        if (!mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $text)
            || preg_match("/^[\\p{Z}\\p{Cf}']*[=+@\\-＝＋＠－]/u", $text)) {
            throw new CatalogException('Enter plain text without control characters or a formula prefix.');
        }

        return match ($field) {
            'sku' => self::sku($text, is_string($value)),
            'name' => self::text($text, 200, true),
            'price' => self::price($text, is_string($value) ? $decimal : '.'),
            'stock' => self::stock($text),
            'categories' => self::categories($text),
            'short_description' => self::text($text, 500, false, true),
            'description' => self::text($text, 4000, false, true),
            default => throw new \LogicException('Unknown product field.'),
        };
    }

    private static function sku(string $text, bool $isText): string
    {
        $valid = $isText
            ? preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$~D', $text)
            : preg_match('/^\d{1,15}$/D', $text);
        if (!$valid) {
            throw new CatalogException('Enter 1–100 SKU characters starting with a letter or digit. Use letters A–Z, digits, periods, dashes, slashes, or underscores. Store long or zero-padded XLSX SKUs as text.');
        }
        return $text;
    }

    private static function price(string $text, string $decimal): string
    {
        $decimal = preg_quote($decimal, '/');
        if (!preg_match('/^(\d{1,9})(?:' . $decimal . '(\d{1,2}))?$/D', $text, $parts)) {
            throw new CatalogException('Enter a non-negative price with up to 9 whole digits and 2 decimal places. Use the selected decimal mark and omit currency symbols and grouping.');
        }
        $whole = ltrim($parts[1], '0');
        return ($whole === '' ? '0' : $whole) . '.' . str_pad($parts[2] ?? '', 2, '0');
    }

    private static function stock(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (!preg_match('/^\d{1,9}$/D', $text)) {
            throw new CatalogException('Enter a whole quantity from 0 to 999,999,999 or leave it blank.');
        }
        return (string) (int) $text;
    }

    private static function categories(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > 500 || strpbrk($text, "<\\\n") !== false) {
            throw new CatalogException('Use up to 500 characters of plain category names on one line.');
        }
        foreach (explode(',', $text) as $category) {
            foreach (explode('>', $category) as $level) {
                if (trim($level) === '' || mb_strlen(trim($level)) > 100) {
                    throw new CatalogException('Use names of 1–100 characters, commas between categories, and > between levels.');
                }
            }
        }
        return $text;
    }

    private static function text(string $text, int $limit, bool $required, bool $multiline = false): string
    {
        if (($required && $text === '') || mb_strlen($text) > $limit) {
            $minimum = $required ? '1' : '0';
            throw new CatalogException("Enter {$minimum}–{$limit} characters.");
        }
        if (str_contains($text, '<') || (!$multiline && str_contains($text, "\n"))
            || ($multiline && (str_contains($text, '[') || str_contains($text, '\n')))) {
            throw new CatalogException('Enter plain text. HTML, shortcodes, and literal backslash-n sequences are unsupported.');
        }
        return $text;
    }
}
