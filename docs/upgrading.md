# Upgrading

What changes between releases, and where to read about it.

## Where changes are recorded

Every release's changes are in [`CHANGELOG.md`](../CHANGELOG.md), newest first, in the Keep a
Changelog sections `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`. Before 1.0 a
minor release (`0.1` to `0.2`) may break the API; the section of that release says what breaks and
what to do, and this page lists the steps.

## If you installed 0.1.0 or 0.1.1 before 2026-11-14

The first `v0.1.0` (2026-11-11) and `v0.1.1` (2026-11-14) were withdrawn, and the history was
squashed into a single `v0.1.0`, published 2026-11-14, that holds everything both carried, plus runtime
overrides. Composer caches an archive per version name, so an install from the old tags keeps the
old code until the cache is cleared:

```bash
composer clear-cache && composer update laranail/settings
```

Your `^0.1` constraint does not change. Nothing else is needed: the API, config keys and tables
are the same as in the withdrawn `v0.1.1`.

## From a pre-release checkout to 0.1.0

0.1.0 is the first tagged release. A checkout taken before it may carry config keys, classes and
commands that were removed before the tag. The `Removed` entries in the CHANGELOG name each one and
its replacement.

A checkout from before the single migration published four migrations
(`create_settings_categories_table`, `create_settings_table`, `create_settings_audit_table`,
`create_settings_definitions_table`). They no longer work: they import classes removed before the
tag (`SchemaShapeRepository`, `IdentityMode`, `OwnerKeyType`, `TranslationsMode`), so a fresh
`migrate` stops on them, and the tables they made have the old shape (integer keys, `slug`, no
`uid`), which 0.1.0 does not read. Delete them and the store migrations an older `make-store`
wrote, publish the one migration with `install`, and create the tables again; copy any values
you need across before dropping the old tables.

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
