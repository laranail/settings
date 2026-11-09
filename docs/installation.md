# Installation

How to add `laranail/settings` to a Laravel 13 application.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.4.1+ or 8.5 |
| Laravel | 13 |
| Database | MySQL / MariaDB, PostgreSQL or SQLite (SQL Server is not supported in 0.x) |

## Composer repositories

`laranail/*` packages are resolved from their GitHub repositories rather than Packagist. Add the
whole family the package needs to your application's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/laranail/settings" },
    { "type": "vcs", "url": "https://github.com/laranail/console" },
    { "type": "vcs", "url": "https://github.com/laranail/db-tools" },
    { "type": "vcs", "url": "https://github.com/laranail/enumerator" },
    { "type": "vcs", "url": "https://github.com/laranail/package-tools" }
]
```

Then:

```bash
composer require laranail/settings
```

The service provider registers itself through package discovery.

## Install and migrate

```bash
php artisan laranail::settings.install
php artisan migrate
```

Install publishes `config/laranail/settings.php` (tag `laranail::settings-config`) and three
migrations (tag `laranail::settings-migrations`): `settings_categories`, `settings` (the
default store's values) and `settings_audit`. The default store reads the database first, so
run the migrations before the first read; until then a read raises `DriverUnavailable`.

Install refuses to publish anything when a table of the same name already exists and is not
this package's (a `settings` table of your own, say). Rename the package's tables with
`tables.prefix`, `stores.settings.table`, `tables.categories` or `tables.audit` and run it again.

## Without a database

Drop `database` from the store's layers and the package keeps values in its JSON runtime file
and shipped PHP defaults, as before:

```php
'stores' => [
    'settings' => ['layers' => ['runtime', 'shipped']],
],
```

To start on files and move to the database later, see
[Moving from files to the database](recipes/moving-from-files-to-the-database.md).

## Supported databases

The package is tested on SQLite, MySQL 8.4 and PostgreSQL 17. SQL Server is not supported in
0.x: it allows one `NULL` per unique index, which breaks the `unique(uid)` index.

---

[← Docs index](../README.md#documentation)
