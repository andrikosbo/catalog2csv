# Testing Catalog2CSV

## Verified environment

The 1.0.0 verification was run on November 19, 2026 against a disposable
WordPress and WooCommerce installation with a separate database for the converter.

| Component | Version and role |
| --- | --- |
| Host | macOS 27.3 on Apple silicon |
| WordPress | 7.1.5, installed locally in a clean database |
| WooCommerce | 11.3.0, active plugin downloaded from WordPress.org |
| Database | MariaDB 11.4.13 and MySQL 8.4.13, isolated Docker containers |
| PHP | 8.5.11 for the local WordPress and converter workflow |
| Apache | 2.4.71 with PHP 8.3.37 and 8.4.28 for the HTTPS shared-hosting layouts |
| Browser automation | Playwright 1.65.1 |
| Browsers | Chromium 159.0.8154.6 at 1440 × 970, WebKit 27.2 at 390 × 844 |
| Accessibility | axe WCAG 2 A/AA and WCAG 2.1 AA scans on upload, mapping, and review |

WooCommerce compatibility was checked against the exact installed release and
the [official built-in importer and schema](https://woocommerce.com/document/product-csv-importer-exporter/).

## WooCommerce import results

The CSVs were downloaded from Catalog2CSV and imported through the WooCommerce
admin interface. WooCommerce automatically mapped the supported headers on
all three imports. The imports added 114 simple products as drafts, with zero
failed or skipped records. Every product was read back through the WooCommerce
product API to verify its SKU, name, regular price, status and type. For exports
with optional columns, the checks also covered category paths, stock management,
quantity and status, and descriptions.

| Input | Expected and observed outcome |
| --- | --- |
| [Built-in office and home supplies XLSX](../examples/supplier-catalog.xlsx) | 12 drafts. SKU `00042` and price `4.50` were preserved. This included hierarchical categories, a product with zero quantity, an unmanaged-stock product, and a multiline description. |
| [DataBrighten 100-product XLSX](../examples/public/databrighten-inventory-100.xlsx) | 100 drafts. Verified all prices and quantities, including `P0001-WH1` at `288.97` with 267 in stock. [Workbook credit and license](../examples/public/README.md). |
| Synthetic Greek CSV with a decimal comma | Two drafts after fixing a row and excluding a duplicate SKU and a formula-like value. Greek names and leading SKU zeroes survived import. |

The [built-in semicolon CSV](../examples/supplier-catalog.csv), using
the comma decimal setting, produces identical bytes to the built-in XLSX
export. The official [WooCommerce sample product CSV](https://github.com/woocommerce/woocommerce/blob/11.3.0/plugins/woocommerce/sample-data/sample_products.csv)
is a separate reference for the destination format.

## Repeat the smoke test

1. Set up Catalog2CSV and an empty staging WordPress installation. Activate
   WooCommerce and sign in to WordPress with product and category management
   permission.
2. Upload the demo XLSX and leave the suggested mapping and Dot setting.
   Review the 12 rows and download the file. The download should match the
   [reference CSV](../examples/woocommerce-products.csv).
3. In WordPress, go to **Products → All Products → Import**. Select the
   generated CSV and leave **Update existing products** unchecked.
4. Confirm the built-in field mapping, click **Run the importer**, and check the
   result. You can run a read-only check from the source checkout:

   ```sh
   php tests/verify-woocommerce.php /path/to/test-wordpress /path/to/download.csv
   ```

5. Repeat with the public XLSX and its [documented mapping](../examples/public/README.md).
   Keep the sample SKUs unique across repeated tests by using a fresh staging database.

## Additional checks

- The PHP suite checks CSV delimiters and BOM, Greek text, quoted and
  multiline records, malformed input, bounds, workbook sheet selection,
  formula blocking, duplicate SKUs, edits and export gating.
- Browser tests covered all three end-to-end flows in Chromium and WebKit,
  source-row pagination, a separate browser session, download blocking,
  and CSRF rejection. The automated accessibility scans returned zero
  violations on the tested screens. This records automated coverage.
- Apache verification exercised the documented document-root and subdirectory
  layouts over HTTPS, rejection of unencrypted requests, the access challenge,
  and denial of direct requests to private files. Both ZIP layouts produced the reference CSV on MariaDB and MySQL. The SQL patch was applied
  twice and the cleanup job removed an expired workspace.
- Run `composer validate --strict`, `composer lint`, `composer test`, and
  `composer audit --locked` from a source checkout. The package audit reported
  zero advisories at release verification.
- Both archive trees were extracted and scanned with Gitleaks 8.30.1. The
  release checks also inspected workbook metadata, ZIP integrity, and upstream
  license files.

Hosting-provider configuration and the operational import on a specific store
should be verified when installing on that target. See [post-deploy checks](DEPLOYMENT.md#post-deploy-checks).
