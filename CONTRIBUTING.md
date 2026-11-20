# Contributing

Install the locked dependencies using `composer install`. Follow the
[local setup](README.md#deploy) and use the bundled fixtures for reproduction.

- Keep changes focused and add a failing example for any parser, validation,
  export or session behavior you fix. Run `composer lint`, `composer test`
  and `composer audit --locked`.
- Keep the public document root confined to `public/` and use a prepared
  statement for any new query that takes values.
- For XLSX or CSV import changes, verify the resulting file in a disposable
  WooCommerce store and mention the versions tested in your pull request.
- Use synthetic or explicitly redistributable fixtures. Keep real catalogs,
  credentials, customer data and local configuration out of contributions.
- Include dependency licenses and update [third-party notices](THIRD_PARTY_NOTICES.md)
  when changing the lock file.

Follow the [security policy](SECURITY.md) for sensitive findings.
