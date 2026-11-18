# Upgrading

What changes between releases, and where to read about it.

## Where changes are recorded

Every release's changes are in [`CHANGELOG.md`](../CHANGELOG.md), newest first, in the Keep a
Changelog sections `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`. Before 1.0 a
minor release (`0.1` to `0.2`) may break the API; the section of that release says what breaks and
what to do, and this page lists the steps.

## If you installed 0.1.0 or 0.1.1 before 2026-11-17

The first `v0.1.0` (2026-11-11) and `v0.1.1` (2026-11-14) were withdrawn, and the history was
squashed into a single `v0.1.0`, published 2026-11-14, that holds everything both carried, plus
runtime overrides. That tag was moved on 2026-11-16 to add `SettingsMisuse` and `SettingsError`,
twice more the same day to carry corrected documentation and clearer install guidance, and on
2026-11-17 to install with no workarounds, then again the same day to add the settings
configurator and stop reading the `LARANAIL_SETTINGS_*` variables (see
[Environment variables](#environment-variables)). Composer caches an archive per version name, so an
install from an earlier tag keeps the old code until the cache is cleared:

```bash
composer clear-cache && composer update laranail/settings
```

Your `^0.1` constraint does not change. Two changes are visible:

- **The default table names carry a `laranail_` prefix** (`laranail_settings`,
  `laranail_settings_categories` and so on), so they no longer collide with an application's own
  `settings` table. If you migrated the bare names, either keep them by setting the prefix to
  empty in your settings configurator, or rename the tables to the new names before you deploy:

  ```php
  $settings->tables(prefix: '');
  ```

  If you set `laranail_` yourself, it is now the default and the call can go.
- Misuse that threw a plain `LogicException` now throws `SettingsMisuse`, which still extends
  `LogicException`, so existing catch blocks keep working.

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

## Environment variables

The package reads no `LARANAIL_SETTINGS_*` variable any more. Set each one in your settings
configurator, the one class you register with `Settings::useConfigurator()` from a service
provider's `register()`, or in the published config file. The config keys and their defaults are
unchanged. `laranail::settings.doctor` and `laranail::settings.configurator` warn about any of these
still set in the environment, and name its replacement.

| Variable | Config key | In the configurator |
|---|---|---|
| `LARANAIL_SETTINGS_STORE` | `default` | `$settings->defaultStore('main')` |
| `LARANAIL_SETTINGS_BOOTSTRAP` | `bootstrap` | `$settings->bootstrap(file_exists(storage_path('app/installing')))` |
| `LARANAIL_SETTINGS_PROMOTE` | `promote` | `$settings->promote('auto')` |
| `LARANAIL_SETTINGS_TABLE_PREFIX` | `tables.prefix` | `$settings->tables(prefix: 'acme_')` |
| `LARANAIL_SETTINGS_TENANCY` | `tenancy.mode` | `$settings->tenancy(fn ($t) => $t->column())` |
| `LARANAIL_SETTINGS_SEED` | `seed.enabled` | `$settings->seed(fn ($s) => $s->enabled(false))` |
| `LARANAIL_SETTINGS_MAX_VALUE_BYTES` | `values.max_bytes` | `$settings->values(fn ($v) => $v->maxBytes(65536))` |
| `LARANAIL_SETTINGS_CACHE` | `cache.enabled` | `$settings->cache(fn ($c) => $c->enabled(false))` |
| `LARANAIL_SETTINGS_CACHE_STORE` | `cache.store` | `$settings->cache(fn ($c) => $c->store('redis'))` |
| `LARANAIL_SETTINGS_DB_CONNECTION` | `layers.database.connection` | `$settings->layer('database', fn ($l) => $l->connection('tenant'))` |
| `LARANAIL_SETTINGS_RUNTIME_DISK` | `layers.runtime.disk` | `$settings->layer('runtime', fn ($l) => $l->disk('s3'))` |
| `LARANAIL_SETTINGS_ACTOR_RESOLVER` | `authentication.resolver` | `$settings->resolveActorsUsing(MyResolver::class)` |
| `LARANAIL_SETTINGS_AUTHORIZER` | `authorization.driver` | `$settings->authorizeUsing(MyAuthorizer::class)` |
| `LARANAIL_SETTINGS_REQUIRE_ACTOR` | `authorization.require_actor` | `$settings->requireActor()` |
| `LARANAIL_SETTINGS_VERIFIER` | `verification.driver` | `$settings->verifyUsing(MyVerifier::class)` |
| `LARANAIL_SETTINGS_LOG_LEVEL` | `logging.level` | `$settings->logging(fn ($l) => $l->level('warning'))` |
| `LARANAIL_SETTINGS_ALERTS` | `alerts.enabled` | `$settings->alerts(fn ($a) => $a->enabled())` |
| `LARANAIL_SETTINGS_PERSISTED_CONFIGURATION` | `configuration.persisted` | `$settings->persistedConfiguration()` |
| `LARANAIL_SETTINGS_HTTP` | `http.enabled` | `$settings->http(fn ($h) => $h->enabled())` |
| `LARANAIL_SETTINGS_HTTP_PREFIX` | `http.prefix` | `$settings->http(fn ($h) => $h->prefix('internal/config'))` |

Read a per-deployment value in the configurator from your own config file (`config('app.…')`),
never with `env()`: once config is cached, `env()` answers null outside config files.

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
