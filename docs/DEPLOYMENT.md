# Deploy Catalog2CSV

## Requirements

- PHP 8.3, 8.4, or 8.5 with PDO MySQL, sessions, mbstring, ctype, GD, iconv,
  fileinfo, filter, libxml, DOM, SimpleXML, XMLReader, XMLWriter, XML, ZIP
  and zlib. On a build host, run `composer check-platform-reqs --no-dev`.
- MySQL 8+ or MariaDB 10.11+ with InnoDB. Use a dedicated database, UTF-8
  (`utf8mb4`), and a server `max_allowed_packet` of at least 16 MiB.
- HTTPS. PHP must see the trusted `HTTPS=on` or `HTTPS=1` server variable.
  Configure your proxy or PHP handler at the hosting layer if it terminates
  TLS. It must pass the Authorization header and discard client-supplied
  scheme/identity overrides.
- Recommended PHP limits: `memory_limit=256M`, `upload_max_filesize=6M`,
  `post_max_size=7M`, `max_execution_time=60`, `display_errors=Off`, and
  `log_errors=On`. Keep PHP session and upload temporary directories outside
  the document root. Run a single app instance unless sessions are shared.
- Plesk or cPanel file access and database setup. Composer is only required
  when building the deployment archive from source.

## Install

1. Extract `catalog2csv-1.0.0-deploy.zip` in a private directory on the hosting
   account. Keep the ZIP and app root outside the public web tree.
2. Create an empty database and a dedicated database user. As the database
   owner, import [001-create-jobs.sql](../database/001-create-jobs.sql). It is
   safe to import this initial patch again. Grant the application user
   `SELECT`, `INSERT`, `UPDATE` and `DELETE` on the app table/database.
3. Copy `config/config.example.php` to `config/config.local.php`. Fill in the
   DSN, credentials and desired access username. With PHP CLI on any trusted
   machine, run `php tools/password.php`. Store the generated plaintext
   password in a password manager and paste the generated hash into the
   config. Use file permissions appropriate for your hosting handler, for
   example 0600 if PHP runs as the account user.
4. Expose only the contents of `public/` using one of the layouts below.
   Enable HTTPS and redirect HTTP to HTTPS at the host.
5. Schedule `php /absolute/path/to/catalog2csv/tools/purge-expired.php` at
   least hourly using the same PHP version and config as the web process.

### Dedicated site or subdomain

Set its document root to the extracted `catalog2csv/public/`. Open the HTTPS
site and sign in with the access user above. The ZIP includes Apache
`.htaccess` for the directory index, Authorization forwarding under CGI/FPM,
disabled directory listing and mount-file denial. If your host configures
these in its virtual host, apply the same rules there.

### Subdirectory of an existing site

Keep the extracted app root outside the website. Copy the files from
`catalog2csv/public/` (including `.htaccess`) into a new public subfolder,
for example `public_html/catalog/`. Add `mount.local.php` to that subfolder,
with no credentials and the actual private application path:

```php
<?php
return '/home/account/private/catalog2csv';
```

Open `https://example.com/catalog/` with the trailing slash. The routes use
query strings and relative links. Exclude the subfolder from any parent CMS
rewrite that captures existing PHP or static files. On Apache, allow the
provided directives through `AllowOverride` or mirror them in the site
configuration. With a non-Apache frontend, configure Authorization forwarding,
`index.php` as directory index, and denial of `mount.local.php` yourself.

## Post-deploy checks

1. Unauthenticated access must return a browser sign-in challenge (HTTP 401).
   An HTTP request should redirect to HTTPS or get HTTP 403 from the app.
2. Confirm the sign-in works and stylesheet loads. Direct requests for
   `config/config.local.php`, `database/001-create-jobs.sql`, `vendor/autoload.php`,
   and `mount.local.php` must not expose private content. Only the last file
   exists in a subdirectory deployment's document root and it should return
   HTTP 403 or 404.
3. Use **Open sample catalog**. Review all 12 items and download the CSV.
   Check SKU `00042`, price `4.50`, and the `Published` value of `-1`.
4. Dry-import the CSV in a disposable or staging WooCommerce store through
   **Products → All Products → Import**. Leave **Update existing products**
   unchecked. Confirm the products are drafts and check prices and SKUs.
5. Confirm the cleanup task runs successfully. Expired catalogs become
   inaccessible after 24 hours and remain in the database until cleanup.
   Database backups and hosting logs follow the operator's retention policy.

Restrict network access to authorized operators where possible and set request
limits using the host or reverse proxy. Do not reuse a store administrator's
credentials for this utility. Catalog2CSV uses one shared access account,
independent browser sessions and no WordPress login or API credentials.

## Updates and rollback

Back up the private config and database, then stage the new ZIP in a separate
private directory. Apply new numbered SQL patches in order if the release
includes them. Point the document root or mount at the staged release and
repeat the checks. Retain the previous release and database backup for rollback.
The 1.0.0 initial schema creates one temporary job table; it does not modify any
WooCommerce database tables.

## Troubleshooting

- **401 loop** — Check the configured access username and hash, then check that
  Apache or the PHP handler forwards the Authorization header.
- **403 on HTTPS** — Check the trusted TLS indicator seen by PHP.
- **503** — Check the PHP error log, extension list, `vendor/`, database schema,
  database user privileges and the path in `mount.local.php` if applicable.
- **Upload fails** — Check PHP and the web server's request-body limit. Upload
  a UTF-8 CSV or XLSX of up to 5 MiB with row-1 headings.
- **Job disappears** — Check the browser cookie, session storage, concurrent
  tabs and the 24-hour expiry. When moving to another browser, upload again.
