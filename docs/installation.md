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

Install publishes `config/laranail/settings.php` (tag `laranail::settings-config`) and the
package's one migration (tag `laranail::settings-migrations`,
`…_create_laranail_settings_tables.php`). The migration creates, in order, `settings_categories`,
`settings_definitions`, one values table per database store (`settings` for the default store),
`settings_audit`, `settings_history`, `settings_change_requests` and `settings_stores`, and
`down()` drops them in reverse. The table shape is fixed (see [Database](tools/database.md)); set
`tenancy.mode`, the table names and any dedicated stores before migrating. The default store
reads the database first, so run the migrations before the first read; until then a read raises
`DriverUnavailable`.

## Default categories

```bash
php artisan db:seed
```

The package registers its seeder with the application's `db:seed`, so `DatabaseSeeder` does not
name it. It creates the `seed.categories` set (general, appearance, localisation, mail,
notifications, security) as system categories and changes nothing when run again. See
[Seed the default categories](recipes/seeding-default-categories.md).

## When install refuses

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

## From an installer

An installer that writes settings before the database exists can let the package move them once
it does. Put the store in bootstrap mode, then ask for the promotion at the start of the install:

```php
Settings::store('settings')->asSystem('installer')->promoteWhenReady();
```

The values the installer writes go to the runtime file. When its `migrate` call finishes, the
store is promoted in the same process. To keep doing this after the install, on every server, set
`'promote' => 'auto'` on the store or `LARANAIL_SETTINGS_PROMOTE=auto`; `promote.include` and
`promote.owners` limit what is moved (see [Configuration](configuration.md)).

## The REST API

The JSON API is off until `http.enabled` is true; nothing is routed before that. It uses the
host's authentication middleware (`auth:sanctum` when laravel/sanctum is installed). See
[REST API](tools/rest-api.md).

## Supported databases

The package is tested on SQLite, MySQL 8.4 and PostgreSQL 17. SQL Server is not supported in
0.x: it allows one `NULL` per unique index, which breaks the `unique(uid)` index.

---

[← Docs index](../README.md#documentation)
