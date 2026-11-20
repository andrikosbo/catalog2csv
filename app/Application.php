<?php

declare(strict_types=1);

namespace Catalog2Csv;

final class Application
{
    private const PAGE_SIZE = 25;
    private JobStore $store;

    public function __construct(private readonly string $root, array $config)
    {
        $this->store = new JobStore($config['database']);
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'POST'], true)) {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }

        $id = $_SESSION['job'] ?? null;
        $job = $id === null ? null : $this->store->find($id);
        if ($method === 'POST') {
            $token = $_POST['csrf'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
                http_response_code(403);
                $this->view('upload', ['job' => $job, 'error' => 'Your session could not be verified. Reload before submitting again.']);
                return;
            }
            $action = $_POST['action'] ?? '';
            try {
                $destination = match ($action) {
                    'upload' => $this->upload($id, false),
                    'demo' => $this->upload($id, true),
                    'map' => $this->saveMapping($id, $this->current($id, $job)),
                    'edit' => $this->saveRow($id, $this->current($id, $job)),
                    'discard' => $this->discard($id, $this->current($id, $job)),
                    default => throw new CatalogException('Choose an action from the workspace.'),
                };
                $this->redirect($destination);
            } catch (CatalogException $exception) {
                http_response_code(422);
                $error = $exception->getMessage();
                if ($action === 'map' && $job !== null) {
                    $this->view('map', ['job' => $job, 'id' => $id, 'error' => $error, 'submitted' => $_POST['columns'] ?? [], 'decimal' => $_POST['decimal'] ?? '']);
                    return;
                }
                $row = $this->number($_POST['row'] ?? null);
                if ($action === 'edit' && $job !== null && isset($job['mapping'], $job['rows'][$row])) {
                    $this->view('edit', $this->editData($id, $job, $row, $error));
                    return;
                }
                $this->view('upload', ['job' => $job, 'error' => $error]);
            }
            return;
        }

        $view = $_GET['view'] ?? 'upload';
        if ($view === 'example') {
            $this->sample();
            return;
        }
        if ($job === null && $view !== 'upload') {
            $this->view('upload', ['job' => null, 'error' => 'This catalog is no longer available. Upload a file to start again.']);
            return;
        }
        if ($view === 'map') {
            $this->view('map', ['job' => $job, 'id' => $id]);
            return;
        }
        if (in_array($view, ['review', 'edit', 'download'], true) && isset($job['mapping'])) {
            $this->review($view, $id, $job);
            return;
        }
        $this->view('upload', ['job' => $job]);
    }

    private function upload(?string $id, bool $demo): string
    {
        if ($demo) {
            $path = $this->root . '/examples/supplier-catalog.xlsx';
            $extension = 'xlsx';
            $delimiter = 'auto';
        } else {
            $file = $_FILES['catalog'] ?? [];
            if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new CatalogException('Choose a CSV or XLSX file up to 5 MiB. If the upload was rejected, check your server upload limits.');
            }
            $path = $file['tmp_name'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $delimiter = $_POST['delimiter'] ?? 'auto';
            if (!is_string($delimiter)) {
                throw new CatalogException('Select a supported CSV delimiter.');
            }
        }
        $payload = (new CatalogReader())->read($path, $extension, $delimiter);
        $_SESSION['job'] = $this->store->replace($id, $payload);
        return '?view=map';
    }

    private function saveMapping(string $id, array $job): string
    {
        $mapping = Products::mapping($_POST['columns'] ?? [], $job['headers']);
        $decimal = $_POST['decimal'] ?? '.';
        if (!in_array($decimal, ['.', ','], true)) {
            throw new CatalogException('Choose a dot or comma decimal mark.');
        }
        if ($mapping !== ($job['mapping'] ?? null) || $decimal !== ($job['decimal'] ?? null)) {
            $job['mapping'] = $mapping;
            $job['decimal'] = $decimal;
            $job['edits'] = [];
            $job['excluded'] = [];
            $this->store->update($id, $job);
        }
        return '?view=review';
    }

    private function saveRow(string $id, array $job): string
    {
        $row = $this->number($_POST['row'] ?? null);
        if (!isset($job['mapping'], $job['rows'][$row])) {
            throw new CatalogException('That source row does not exist.');
        }

        $previous = Products::values($job, $row);
        $values = $this->editableValues($job, $row);
        foreach (array_keys($values) as $field) {
            $value = is_array($_POST['fields'] ?? null) ? ($_POST['fields'][$field] ?? null) : null;
            if (!is_string($value) || strlen($value) > 8192 || !mb_check_encoding($value, 'UTF-8')) {
                throw new CatalogException('Choose valid UTF-8 text of up to 8 KiB per field.');
            }
            // An unchanged invalid XLSX cell must remain flagged, even when optional.
            if ($field === 'sku' && !is_string($previous[$field]) && $value === (string) $previous[$field]) {
                $values[$field] = $previous[$field];
            } elseif ($previous[$field] !== null || $value !== '') {
                $values[$field] = $value;
            } else {
                $values[$field] = ($_POST['clear_formula'][$field] ?? null) === '1' ? '' : null;
            }
        }

        $job['edits'][$row] = $values;
        if (($_POST['exclude'] ?? '') === '1') {
            $job['excluded'][$row] = true;
        } else {
            unset($job['excluded'][$row]);
        }
        $this->store->update($id, $job);
        return '?view=review&page=' . ($this->number($_POST['page'] ?? '1') ?: 1)
            . (($_POST['filter'] ?? '') === 'issues' ? '&filter=issues' : '');
    }

    private function discard(string $id, array $job): string
    {
        $this->store->remove($id);
        unset($_SESSION['job']);
        return './';
    }

    private function review(string $view, string $id, array $job): void
    {
        $review = Products::review($job);
        $issues = array_filter($review, static fn (array $item): bool => !$item['excluded'] && $item['errors'] !== []);
        $excluded = count(array_filter($review, static fn (array $item): bool => $item['excluded']));
        if ($view === 'download' && $issues === [] && $excluded < count($review)) {
            $this->download($review, $job['mapping']);
            return;
        }
        if ($view === 'edit') {
            $row = $this->number($_GET['row'] ?? null);
            if (isset($review[$row])) {
                $this->view('edit', $this->editData($id, $job, $row, null, $review[$row]['errors']));
                return;
            }
        }

        $flaggedOnly = ($_GET['filter'] ?? '') === 'issues';
        $visible = $flaggedOnly ? $issues : $review;
        $pages = max(1, (int) ceil(count($visible) / self::PAGE_SIZE));
        $page = min($pages, max(1, $this->number($_GET['page'] ?? '1')));
        $data = array_slice($visible, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);
        if ($view === 'download') {
            http_response_code(422);
        }
        $this->view('review', compact('id', 'job', 'review', 'issues', 'excluded', 'data', 'page', 'pages', 'flaggedOnly'));
    }

    private function editableValues(array $job, int $row): array
    {
        $values = Products::values($job, $row);
        if (is_int($values['price']) || is_float($values['price'])) {
            $values['price'] = str_replace('.', $job['decimal'], (string) $values['price']);
        }
        return $values;
    }

    private function editData(string $id, array $job, int $row, ?string $error = null, ?array $fieldErrors = null): array
    {
        $values = $this->editableValues($job, $row);
        $fieldErrors ??= Products::review($job)[$row]['errors'];
        $page = $this->number($_GET['page'] ?? $_POST['page'] ?? '1') ?: 1;
        return compact('id', 'job', 'row', 'values', 'fieldErrors', 'page', 'error');
    }

    private function sample(): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="supplier-catalog.xlsx"');
        readfile($this->root . '/examples/supplier-catalog.xlsx');
    }

    private function download(array $review, array $mapping): void
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open export stream.');
        }
        try {
            CsvExporter::write($stream, $review, $mapping);
            rewind($stream);
            session_write_close();
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="woocommerce-products.csv"');
            fpassthru($stream);
        } finally {
            fclose($stream);
        }
    }

    private function current(?string $id, ?array $job): array
    {
        $version = $_POST['version'] ?? '';
        if ($id === null || $job === null || !is_string($version) || !hash_equals($this->version($id, $job), $version)) {
            throw new CatalogException('The catalog changed in another tab or expired. Reload and try again.');
        }
        return $job;
    }

    private function version(string $id, array $job): string
    {
        return hash_hmac('sha256', $id . ':' . $job['revision'], $_SESSION['csrf']);
    }

    private function number(mixed $value): int
    {
        return is_string($value) && strlen($value) <= 9 && ctype_digit($value) ? (int) $value : 0;
    }

    private function redirect(string $location): never
    {
        session_write_close();
        header('Location: ' . $location, true, 303);
        exit;
    }

    private function view(string $view, array $variables): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        require $this->root . '/app/view.php';
    }
}
