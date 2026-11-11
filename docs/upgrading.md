# Upgrading

What changes between releases, and where to read about it.

## Where changes are recorded

Every release's changes are in [`CHANGELOG.md`](../CHANGELOG.md), newest first, in the Keep a
Changelog sections `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`. Before 1.0 a
minor release (`0.1` to `0.2`) may break the API; the section of that release says what breaks and
what to do, and this page lists the steps.

## From a pre-release checkout to 0.1.0

0.1.0 is the first tagged release. A checkout taken before it may carry config keys, classes and
commands that were removed before the tag. The `Removed` entries in the CHANGELOG name each one and
its replacement.

A checkout from before the single migration published four migrations
(`create_settings_categories_table`, `create_settings_table`, `create_settings_audit_table`,
`create_settings_definitions_table`). They stay in your `database/migrations` and keep working; do
not publish the new one next to them, or the tables would be created twice. Store migrations
written by an older `make-store` keep working too.

1. Compare your `config/laranail/settings.php` with the package's `config/settings.php`, and drop
   any key that [Configuration](configuration.md) does not list.
2. Re-run `php artisan laranail::settings.doctor`. It checks that every column the package uses
   exists.

## After any upgrade

```bash
composer update laranail/settings
php artisan migrate
php artisan laranail::settings.doctor
php artisan laranail::settings.clear-cache
```

`laranail/*` packages resolve from their GitHub repositories, so run `composer clear-cache` first if
a new tag does not seem to arrive.

---

[← Docs index](../README.md#documentation)
