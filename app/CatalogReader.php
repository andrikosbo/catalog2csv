<?php

declare(strict_types=1);

namespace Catalog2Csv;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use XMLReader;
use ZipArchive;

final class CatalogReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    public const MAX_COLUMNS = 32;
    private const MAX_CELLS = 80000;
    private const MAX_CELL_BYTES = 8192;
    private const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];

    /**
     * Record keys are source line/row numbers. A null cell is an Excel formula,
     * error, or unsupported type and requires correction if mapped.
     */
    public function read(string $path, string $extension, string $delimiter = 'auto'): array
    {
        if (!is_file($path) || filesize($path) < 1 || filesize($path) > self::MAX_BYTES) {
            throw new CatalogException('Choose a non-empty CSV or XLSX file of up to 5 MiB.');
        }

        [$header, $rows, $source] = match ($extension) {
            'csv' => $this->readCsv($path, $delimiter),
            'xlsx' => $this->readXlsx($path),
            default => throw new CatalogException('Choose a .csv or .xlsx file.'),
        };

        if (count($header) < 3 || count(array_filter($header, static fn ($item) => $item !== '')) < 3) {
            throw new CatalogException('The first record must contain at least three named column headers.');
        }
        if (count($header) > self::MAX_COLUMNS || $rows === [] || count($rows) > self::MAX_ROWS) {
            throw new CatalogException('Use up to 32 columns and between 1 and 5,000 data rows.');
        }

        return ['headers' => $header, 'rows' => $rows, 'source' => $source];
    }

    private function readCsv(string $path, string $choice): array
    {
        $text = file_get_contents($path);
        if ($text === false || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            throw new CatalogException('Save the CSV as UTF-8 text, then upload it again.');
        }
        $text = str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($choice !== 'auto' && !isset(self::DELIMITERS[$choice])) {
            throw new CatalogException('Select a supported CSV delimiter.');
        }
        if ($choice === 'auto') {
            $candidates = [];
            foreach (self::DELIMITERS as $key => $delimiter) {
                $start = 0;
                try {
                    $header = $this->readRecord($text, $start, $delimiter, 1);
                    if (count($header) >= 3) {
                        $candidates[] = $key;
                    }
                } catch (CatalogException) {
                    // A valid quoted header may look malformed under the wrong delimiter.
                }
            }
            if (count($candidates) !== 1) {
                throw new CatalogException('The CSV delimiter could not be identified. Select it explicitly and check the header.');
            }
            $choice = $candidates[0];
        }

        $delimiter = self::DELIMITERS[$choice];
        $offset = 0;
        $header = $this->headers($this->readRecord($text, $offset, $delimiter, 1));
        $line = 1 + substr_count($text, "\n", 0, $offset);
        $rows = [];
        $cells = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $start = $offset;
            $row = $this->readRecord($text, $offset, $delimiter, $line);
            if (array_filter($row, static fn ($value) => trim($value) !== '') !== []) {
                if (count($row) !== count($header)) {
                    throw new CatalogException("Line {$line} has a different number of columns from the header.");
                }
                $rows[$line] = $row;
                $cells += count($row);
                if (count($rows) > self::MAX_ROWS || $cells > self::MAX_CELLS) {
                    throw new CatalogException('Split the CSV into smaller catalogs (5,000 rows and 80,000 cells per file).');
                }
            }
            $line += substr_count($text, "\n", $start, $offset - $start);
        }

        return [$header, $rows, strtoupper($choice === 'tab' ? 'TSV' : 'CSV') . ' / ' . $choice];
    }

    private function readRecord(string $text, int &$offset, string $delimiter, int $line): array
    {
        $cells = [];
        $length = strlen($text);

        do {
            if (($text[$offset] ?? '') === '"') {
                $offset++;
                $value = '';
                while (true) {
                    $quote = strpos($text, '"', $offset);
                    if ($quote === false || strlen($value) + $quote - $offset > self::MAX_CELL_BYTES) {
                        throw new CatalogException("Line {$line} has an unclosed or oversized quoted field.");
                    }
                    $value .= substr($text, $offset, $quote - $offset);
                    $offset = $quote + 1;
                    if (($text[$offset] ?? '') !== '"') {
                        break;
                    }
                    $value .= '"';
                    $offset++;
                }
                if (!in_array($text[$offset] ?? '', ['', "\n", $delimiter], true)) {
                    throw new CatalogException("Line {$line} has characters after a closing quote.");
                }
            } else {
                $span = strcspn($text, $delimiter . "\"\n", $offset);
                $value = substr($text, $offset, $span);
                $offset += $span;
                if (($text[$offset] ?? '') === '"') {
                    throw new CatalogException("Line {$line} has a quote inside an unquoted field.");
                }
            }

            if (strlen($value) > self::MAX_CELL_BYTES || count($cells) >= self::MAX_COLUMNS) {
                throw new CatalogException("Line {$line} exceeds 32 columns or the 8 KiB limit per cell.");
            }
            $cells[] = $value;
            $separator = $text[$offset] ?? '';
            if ($offset < $length) {
                $offset++;
            }
        } while ($separator === $delimiter);

        return $cells;
    }

    private function readXlsx(string $path): array
    {
        $this->inspectArchive($path);
        try {
            $reader = new Xlsx();
            $info = $reader->listWorksheetInfo($path);
            $first = $info[0] ?? null;
            if (!$first || $first['totalRows'] > self::MAX_ROWS + 1 || $first['totalColumns'] > self::MAX_COLUMNS) {
                throw new CatalogException('The first worksheet must fit within 5,001 rows including headers and 32 columns.');
            }
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setIgnoreRowsWithNoCells(true);
            $reader->setLoadSheetsOnly($first['worksheetName']);
            $book = $reader->load($path);
        } catch (CatalogException $exception) {
            throw $exception;
        } catch (\Exception) {
            throw new CatalogException('The workbook could not be read. Save it again as .xlsx and retry.');
        }

        try {
            $sheet = $book->getSheet(0);
            $width = $first['totalColumns'];
            $lastRow = $first['totalRows'];
            $header = [];
            $rows = [];
            $totalBytes = 0;

            for ($index = 1; $index <= $lastRow; $index++) {
                $values = [];
                for ($column = 1; $column <= $width; $column++) {
                    $coordinate = [$column, $index];
                    if (!$sheet->cellExists($coordinate)) {
                        $values[] = '';
                        continue;
                    }
                    $cell = $sheet->getCell($coordinate);
                    $value = $cell->getValue();
                    if (in_array($cell->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)
                        || (is_numeric($value) && is_float($value) && !is_finite($value))) {
                        $values[] = null;
                        continue;
                    }
                    if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    }
                    $value ??= '';
                    if (is_bool($value) || (!is_scalar($value))) {
                        $values[] = null;
                        continue;
                    }
                    $byteCount = strlen((string) $value);
                    $totalBytes += $byteCount;
                    if ($byteCount > self::MAX_CELL_BYTES || $totalBytes > self::MAX_BYTES) {
                        throw new CatalogException('The decoded worksheet exceeds 5 MiB or contains a cell larger than 8 KiB.');
                    }
                    $values[] = $value;
                }
                if ($index === 1) {
                    $header = $this->headers($values);
                } elseif (array_filter($values, static fn ($value) => $value === null || trim((string) $value) !== '') !== []) {
                    $rows[$index] = $values;
                }
            }

            return [$header, $rows, 'XLSX / first worksheet'];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function headers(array $values): array
    {
        return array_map(static function ($value): string {
            $header = trim((string) $value);
            if ($value === null || mb_strlen($header) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $header)) {
                throw new CatalogException('Use plain-text column headers of up to 120 characters.');
            }
            return $header;
        }, $values);
    }

    private function inspectArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new CatalogException('The file is not a readable XLSX archive.');
        }
        try {
            if ($zip->numFiles > 128 || $zip->locateName('xl/workbook.xml') === false
                || $zip->locateName('[Content_Types].xml') === false) {
                throw new CatalogException('Use a standard, unencrypted XLSX workbook with at most 128 archive entries.');
            }
            $bytes = 0;
            $cells = 0;
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false) {
                    throw new CatalogException('The XLSX archive has a damaged entry.');
                }
                $name = $entry['name'];
                $bytes += $entry['size'];
                if ($entry['size'] > 8 * 1024 * 1024 || $bytes > 20 * 1024 * 1024
                    || $entry['encryption_method'] !== ZipArchive::EM_NONE
                    || $name === '' || $name[0] === '/' || str_contains($name, '\\')
                    || str_contains($name, ':') || preg_match('~(?:^|/)\.\.?(?:/|$)~', $name)
                    || isset($seen[strtolower($name)]) || preg_match('~(?:vbaProject\.bin|^xl/externalLinks/)~i', $name)) {
                    throw new CatalogException('The workbook exceeds safe XLSX limits or contains unsupported archive entries.');
                }
                $seen[strtolower($name)] = true;
                if (!preg_match('~\.(?:xml|rels)$~i', $name)) {
                    continue;
                }
                $xml = $zip->getFromIndex($index);
                if ($xml === false) {
                    throw new CatalogException('The XLSX workbook contains damaged worksheets.');
                }
                $this->inspectXml($xml, $cells);
            }
        } finally {
            $zip->close();
        }
    }

    private function inspectXml(string $xml, int &$cells): void
    {
        $previous = libxml_use_internal_errors(true);
        $reader = new XMLReader();
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET)) {
                throw new CatalogException('The workbook contains invalid XML.');
            }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->depth > 40) {
                    throw new CatalogException('The workbook contains unsupported XML.');
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c'
                    && $reader->namespaceURI === 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
                    && ++$cells > self::MAX_CELLS) {
                    throw new CatalogException('Split the workbook into smaller files (80,000 cells per workbook).');
                }
            }
            if (libxml_get_errors() !== []) {
                throw new CatalogException('The workbook contains invalid XML.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
