# Redaquest Connector — developer notes

Run all commands from this directory (`public/downloads/redaquest-connector/`).

## Dependencies

```bash
composer install
```

## Tests

```bash
composer test
```

Unit tests cover custom field sanitization/export rules, REST schema validation, and rate limiting (PHPUnit with WordPress function stubs in `tests/bootstrap.php`).

## Lint (PHPCS + WPCS)

```bash
vendor/bin/phpcs --config-set installed_paths vendor/wp-coding-standards/wpcs
composer lint
```

## Translations

Source strings use the `redaquest-connector` text domain (Slovak as default msgid).

- Template: `languages/redaquest-connector.pot`
- Locales: `languages/redaquest-connector-sk_SK.po`, `languages/redaquest-connector-en_US.po`
- Compiled MO files are included in the downloadable ZIP

Recompile after editing PO files:

```bash
msgfmt -o languages/redaquest-connector-sk_SK.mo languages/redaquest-connector-sk_SK.po
msgfmt -o languages/redaquest-connector-en_US.mo languages/redaquest-connector-en_US.po
```

## CI

GitHub Actions workflow `.github/workflows/wp-plugin-phpcs.yml` runs PHPCS and PHPUnit on changes under this directory.

## ZIP distribution

Runtime files are listed in `src/constants/redaquestPlugin.ts` (`REDAQUEST_PLUGIN_FILES`). Dev-only files (`composer.json`, `phpunit.xml.dist`, `tests/`, `phpcs.xml.dist`, `DEVELOPER.md`, `.po`/`.pot`) are excluded from the ZIP.
