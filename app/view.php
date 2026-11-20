<?php

declare(strict_types=1);

namespace Catalog2Csv;

extract($variables, EXTR_SKIP);
$step = ['upload' => 1, 'map' => 2, 'review' => 3, 'edit' => 3][$view];
$title = [
    'upload' => 'Convert a supplier catalog',
    'map' => 'Map your columns',
    'review' => 'Review your products',
    'edit' => 'Edit source row ' . ($row ?? ''),
][$view];
$csrf = $_SESSION['csrf'];
$version = isset($id, $job) ? $this->version($id, $job) : null;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Catalog2CSV</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="./"><span class="brand-mark" aria-hidden="true"></span>Catalog2CSV</a>
        <span class="header-note">Supplier catalog converter</span>
    </div>
</header>
<main id="main" class="wrap" tabindex="-1">
    <nav aria-label="Conversion progress" class="progress">
        <ol>
            <?php foreach ([1 => ['Upload', './'], 2 => ['Map columns', '?view=map'], 3 => ['Review & export', '?view=review']] as $index => [$label, $url]): ?>
                <li <?= $index === $step ? 'aria-current="step"' : '' ?>>
                    <span class="step-number" aria-hidden="true"><?= e($index) ?></span>
                    <?php if (($index === 1 && $step !== 1) || ($index === 2 && isset($job['headers']) && $step > 2)
                        || ($index === 3 && $view === 'edit')): ?>
                        <a href="<?= e($url) ?>"><?= e($label) ?></a>
                    <?php else: ?>
                        <?= e($label) ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <h1><?= e($title) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="notice danger" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($view === 'upload'): ?>
        <p class="intro">Turn a supplier’s Excel or CSV price list into a WooCommerce product CSV. Check prices and SKUs before anything reaches your store.</p>
        <div class="upload-grid">
            <section class="panel upload-panel">
                <form action="./" method="post" enctype="multipart/form-data" aria-labelledby="upload-heading">
                    <h2 id="upload-heading">Upload a catalog</h2>
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="upload">
                    <label for="catalog">Catalog file (required)</label>
                    <input id="catalog" type="file" name="catalog" required accept=".csv,.xlsx" aria-describedby="file-help">
                    <p id="file-help" class="hint">CSV or XLSX, up to 5 MiB. Put the column headers on the first row.</p>
                    <details>
                        <summary>CSV options</summary>
                        <label for="delimiter">Field delimiter</label>
                        <select id="delimiter" name="delimiter">
                            <option value="auto">Detect automatically</option>
                            <option value="comma">Comma</option>
                            <option value="semicolon">Semicolon</option>
                            <option value="tab">Tab</option>
                        </select>
                    </details>
                    <?php if ($job !== null): ?><p class="hint">A successful upload replaces the catalog in this session.</p><?php endif; ?>
                    <button type="submit">Upload and map columns</button>
                </form>
            </section>
            <aside class="panel demo-panel" aria-labelledby="demo-heading">
                <div class="file-mark" aria-hidden="true">XLSX</div>
                <h2 id="demo-heading">Try the sample catalog</h2>
                <p>Start with a 12-product price list of everyday office and home supplies.</p>
                <form action="./" method="post" aria-labelledby="demo-heading">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="demo">
                    <button class="secondary" type="submit">Open sample catalog</button>
                </form>
                <a href="?view=example">Download sample XLSX</a>
            </aside>
        </div>
        <?php if ($job !== null): ?>
            <p class="notice">A catalog is already in progress. <a href="<?= isset($job['mapping']) ? '?view=review' : '?view=map' ?>">Continue this catalog</a>.</p>
        <?php endif; ?>
    <?php elseif ($view === 'map'):
        $selection = $job['mapping'] ?? Products::suggest($job['headers']);
        $decimal = (isset($decimal) && is_string($decimal) && $decimal !== '') ? $decimal : ($job['decimal'] ?? '.');
        ?>
        <p class="intro">Match the headers in your file to WooCommerce fields. SKU, name and regular price are required.</p>
        <section class="panel">
            <h2 id="map-heading">Column mapping</h2>
            <form action="./" method="post" aria-labelledby="map-heading">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="map">
                <input type="hidden" name="version" value="<?= e($version) ?>">
                <div class="mapping-grid">
                    <?php foreach (Products::FIELDS as $field => [$label, $required]):
                        $selected = isset($submitted) && is_array($submitted) ? ($submitted[$field] ?? '') : ($selection[$field] ?? '');
                        ?>
                        <div class="field">
                            <label for="col-<?= e($field) ?>"><?= e($label) ?> <small><?= $required ? 'Required' : 'Optional' ?></small></label>
                            <select id="col-<?= e($field) ?>" name="columns[<?= e($field) ?>]" <?= $required ? 'required' : '' ?>>
                                <option value=""><?= $required ? 'Choose a source column' : 'Do not include' ?></option>
                                <?php foreach ($job['headers'] as $index => $header): ?>
                                    <option value="<?= e($index) ?>" <?= is_scalar($selected) && (string) $index === (string) $selected ? 'selected' : '' ?>>
                                        <?= e($header !== '' ? $header : 'Untitled') ?> (<?= e($index + 1) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <fieldset aria-describedby="decimal-help">
                    <legend>Decimal mark in text prices</legend>
                    <label class="choice"><input type="radio" name="decimal" value="." <?= $decimal !== ',' ? 'checked' : '' ?>> Dot (12.50)</label>
                    <label class="choice"><input type="radio" name="decimal" value="," <?= $decimal === ',' ? 'checked' : '' ?>> Comma (12,50)</label>
                    <p class="hint" id="decimal-help">This setting applies to CSV or text cells in Excel. Omit grouping marks and currency symbols.</p>
                </fieldset>
                <?php if (!empty($job['edits'])): ?><p class="notice">Changing the mapping or decimal mark clears the row edits and exclusions.</p><?php endif; ?>
                <button type="submit">Review products</button>
            </form>
        </section>
        <details class="panel preview">
            <summary id="preview-heading">Preview the first 3 source rows</summary>
            <div class="table-frame" tabindex="0" role="region" aria-labelledby="preview-caption">
                <table>
                    <caption id="preview-caption"><?= e(count($job['rows'])) ?> rows · <?= e($job['source']) ?></caption>
                    <thead><tr><th scope="col">Source row</th><?php foreach ($job['headers'] as $index => $header): ?><th scope="col"><?= e($header ?: 'Column ' . ($index + 1)) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($job['rows'], 0, 3, true) as $index => $values): ?>
                        <tr><th scope="row"><?= e($index) ?></th><?php foreach ($values as $value): ?><td><?= e($value === null ? 'Unsupported cell' : mb_strimwidth((string) $value, 0, 96, '…')) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php elseif ($view === 'review'):
        $ready = count($review) - count($issues) - $excluded;
        $filter = $flaggedOnly ? '&filter=issues' : '';
        ?>
        <p class="intro">Correct or exclude flagged rows. The CSV creates simple products as drafts in WooCommerce.</p>
        <dl class="stats" aria-label="Catalog status">
            <div><dt>Ready to export</dt><dd><?= e($ready) ?></dd></div>
            <div><dt>Need attention</dt><dd><?= e(count($issues)) ?></dd></div>
            <div><dt>Excluded</dt><dd><?= e($excluded) ?></dd></div>
        </dl>
        <section class="panel review-panel">
            <div class="review-heading">
                <div>
                    <h2>Product rows</h2>
                    <p class="hint"><?= e($job['source']) ?> · <?= e(count($review)) ?> source rows</p>
                </div>
                <?php if ($issues === [] && $ready > 0): ?>
                    <a class="button" href="?view=download">Download WooCommerce CSV</a>
                <?php elseif ($issues !== []): ?>
                    <p class="export-status">Download available when issues are resolved.</p>
                <?php else: ?>
                    <p class="export-status">Include at least one product to download.</p>
                <?php endif; ?>
            </div>
            <nav class="filters" aria-label="Review filters">
                <a href="?view=review" <?= !$flaggedOnly ? 'aria-current="page"' : '' ?>>All rows</a>
                <a href="?view=review&amp;filter=issues" <?= $flaggedOnly ? 'aria-current="page"' : '' ?>>Needs attention</a>
            </nav>
            <?php if ($data !== []): ?>
                <div class="table-frame" tabindex="0" role="region" aria-labelledby="review-caption">
                    <table>
                        <caption id="review-caption" class="visually-hidden">Product status and field validation</caption>
                        <thead>
                            <tr>
                                <th scope="col">Row</th><th scope="col">SKU</th><th scope="col">Name</th><th scope="col">Regular price</th>
                                <?php foreach ($job['mapping'] as $field => $column): ?>
                                    <?php if ($column !== null && !Products::FIELDS[$field][1]): ?><th scope="col"><?= e(Products::FIELDS[$field][0]) ?></th><?php endif; ?>
                                <?php endforeach; ?>
                                <th scope="col">Status</th><th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($data as $row => $item):
                            $fields = $item['fields'];
                            ?>
                            <tr>
                                <th scope="row"><?= e($row) ?></th>
                                <?php foreach ($job['mapping'] as $field => $column):
                                    if ($column === null) {
                                        continue;
                                    }
                                    ?>
                                    <td><?= e($fields[$field] === '' ? '—' : mb_strimwidth($fields[$field], 0, 96, '…')) ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if ($item['excluded']): ?><span class="badge muted">Excluded</span>
                                    <?php elseif ($item['errors'] !== []): ?>
                                        <span class="badge flagged">Needs attention</span>
                                        <ul class="issues"><?php foreach ($item['errors'] as $field => $message): ?><li><?= e(Products::FIELDS[$field][0]) ?> · <?= e($message) ?></li><?php endforeach; ?></ul>
                                    <?php else: ?><span class="badge ready">Ready</span><?php endif; ?>
                                </td>
                                <td><a href="?view=edit&amp;row=<?= e($row) ?>&amp;page=<?= e($page) ?><?= e($filter) ?>" aria-label="Edit source row <?= e($row) ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages > 1): ?>
                    <nav class="pagination" aria-label="Product pages">
                        <?php if ($page > 1): ?><a href="?view=review&amp;page=<?= e($page - 1) ?><?= e($filter) ?>">Previous</a><?php endif; ?>
                        <span>Page <?= e($page) ?> of <?= e($pages) ?></span>
                        <?php if ($page < $pages): ?><a href="?view=review&amp;page=<?= e($page + 1) ?><?= e($filter) ?>">Next</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php else: ?>
                <p class="notice">No included rows need attention.</p>
            <?php endif; ?>
        </section>
        <div class="review-footer">
            <a href="?view=map">Change column mapping</a>
            <form action="./" method="post" aria-label="Discard current catalog">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="discard">
                <input type="hidden" name="version" value="<?= e($version) ?>">
                <button class="quiet" type="submit">Discard this catalog</button>
            </form>
        </div>
    <?php else:
        $filter = (($_GET['filter'] ?? $_POST['filter'] ?? '') === 'issues') ? '&filter=issues' : '';
        ?>
        <p class="intro">Changes apply to this export. Your original file stays intact.</p>
        <form class="panel edit-panel" action="./" method="post" aria-labelledby="edit-heading">
            <h2 id="edit-heading">Product details</h2>
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="version" value="<?= e($version) ?>">
            <input type="hidden" name="row" value="<?= e($row) ?>">
            <input type="hidden" name="page" value="<?= e($page) ?>">
            <input type="hidden" name="filter" value="<?= $filter !== '' ? 'issues' : '' ?>">
            <?php foreach ($values as $field => $value):
                $label = Products::FIELDS[$field][0];
                $described = isset($fieldErrors[$field]) ? ' aria-invalid="true" aria-describedby="err-' . $field . '"' : '';
                ?>
                <div class="field">
                    <label for="edit-<?= e($field) ?>"><?= e($label) ?><?= Products::FIELDS[$field][1] ? ' (required for export)' : '' ?></label>
                    <?php if (in_array($field, ['description', 'short_description'], true)): ?>
                        <textarea id="edit-<?= e($field) ?>" name="fields[<?= e($field) ?>]" rows="3"<?= $described ?>><?= e($value) ?></textarea>
                    <?php else: ?>
                        <input id="edit-<?= e($field) ?>" name="fields[<?= e($field) ?>]" type="text" value="<?= e($value) ?>"<?= $described ?>
                            <?= $field === 'price' ? 'inputmode="decimal"' : '' ?> <?= $field === 'stock' ? 'inputmode="numeric"' : '' ?>
                            <?= $field === 'sku' ? 'spellcheck="false" autocapitalize="none"' : '' ?>>
                    <?php endif; ?>
                    <?php if (isset($fieldErrors[$field])): ?><p class="hint error-text" id="err-<?= e($field) ?>"><?= e($fieldErrors[$field]) ?></p><?php endif; ?>
                    <?php if ($value === null && !Products::FIELDS[$field][1]): ?>
                        <label class="choice"><input type="checkbox" name="clear_formula[<?= e($field) ?>]" value="1"> Clear unsupported value if left blank</label>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <label class="choice"><input type="checkbox" name="exclude" value="1" <?= isset($job['excluded'][$row]) ? 'checked' : '' ?>> Exclude this row from the CSV</label>
            <div class="actions">
                <button type="submit">Save row</button>
                <a href="?view=review&amp;page=<?= e($page) ?><?= e($filter) ?>">Back to review</a>
            </div>
        </form>
    <?php endif; ?>
</main>
<footer class="wrap site-footer">Catalog sessions expire after 24 hours.</footer>
</body>
</html>
