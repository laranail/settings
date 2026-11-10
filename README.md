# laranail/settings

[![Tests](https://github.com/laranail/settings/actions/workflows/tests.yml/badge.svg)](https://github.com/laranail/settings/actions/workflows/tests.yml)
[![Static analysis](https://github.com/laranail/settings/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/laranail/settings/actions/workflows/static-analysis.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

`laranail/settings` is not published to Packagist, so there is no registry-version badge to show: see [Install](#install).

> Key-value settings for any Laravel owner (the application, a user, a tenant, a product), stored in layered stores that work before the database exists.

Requires PHP 8.4.1+ or 8.5 and Laravel 13. Works on MySQL, PostgreSQL and SQLite; SQL Server is not supported in 0.x.

> **Pre-release.** The engine, caching, history, approvals, import and export, the opt-in REST API
> and typed settings classes are built; the 0.1 release is being prepared. See
> [Getting started](docs/getting-started.md).

## Install

```bash
composer require laranail/settings
```

`laranail/*` packages resolve from their GitHub repositories, not Packagist; see
[Installation](docs/installation.md) for the `repositories` entries.

## Quick start guide and usage

### Getting started

Publish the config and the package's one migration (it creates `settings_categories`, a values
table per database store, `settings_audit`, `settings_history` and `settings_change_requests`), migrate, and optionally seed the default
categories: the default store reads the database first, then its runtime file, then shipped
defaults from `settings/defaults.php` when that file exists.

```bash
php artisan laranail::settings.install
php artisan migrate
php artisan db:seed     # general, appearance, localisation, mail, notifications, security
```

For multi-tenant applications set `tenancy.mode` before migrating (see
[Tenancy](docs/tools/tenancy.md)).

An application without a database drops `database` from `stores.settings.layers` and keeps its
values in `storage/app/private/laranail/settings/settings.json`.

### Usage

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Settings::set('site.name', 'Acme');
Settings::get('site.name');                 // 'Acme'
Settings::get('site.tagline', 'Welcome');   // the default when nothing is stored
Settings::many(['site.name', 'site.tagline' => 'Welcome']);
Settings::all('mail');                      // every visible key under mail.
```

```php
// Per owner, in the store that accepts its alias, falling back to parents when allowed.
Settings::for($user)->set('ui.theme', 'dark');
Settings::for($user)->get('ui.theme', 'light');
```

```php
// On a user's behalf: checked against the laranail-settings.* gates and audited.
Settings::as($user)->because('INC-123')->set('mail.from.address', 'ap@acme.test');
```

```php
// A typed class over the same keys (loaded and saved through the same write actions).
$mail = MailSettings::load(Settings::as($user));
$mail->fromAddress = 'ops@acme.test';
$mail->save();
```

The JSON REST API is off until `http.enabled` is true; see [REST API](docs/tools/rest-api.md).

[Getting started](docs/getting-started.md) walks through stores, owners and definitions; the
[Documentation](#documentation) index lists every page.

## <a name="documentation"></a>Documentation

The hosted docs live at <https://opensource.simtabi.com/documentation/laranail/settings/>.

### Guides

- [Installation](docs/installation.md) — Composer repositories, requirements and the install command.
- [Getting started](docs/getting-started.md) — stores, owners and definitions in a first application.
- [Configuration](docs/configuration.md) — every key in `config/laranail/settings.php`.
- [Architecture](docs/architecture.md) — stores, layers, owners, categories and why they are shaped this way.
- [Release](docs/release.md) — versioning, tags and how a release is cut.

### Reference

- [Settings API](docs/tools/settings-api.md) — every method of the handle, the facade, the `settings()` helper, `HasSettings` and typed settings, with examples, exceptions and events.
- [Exceptions](docs/tools/exceptions.md) — every exception, when it is raised and how the REST API answers it.
- [Definitions](docs/tools/definitions.md) — types, defaults, rules, encryption, locks and precedence.
- [Drivers and layers](docs/tools/drivers.md) — the built-in drivers and custom ones.
- [Database](docs/tools/database.md) — the tables, version-checked writes, eager loading and `HasSettings`.
- [Categories](docs/tools/categories.md) — nested, per-store categories and the rules that guard them.
- [Commands](docs/tools/commands.md) — install, make-store, promote, doctor, warm, clear-cache, history, export, import, diff, reencrypt, rescope and the prune commands.
- [Owners and inheritance](docs/tools/owners.md) — owner references, store routing and the cascade.
- [Access control](docs/tools/access-control.md) — actors, abilities, authorizers, guarded locks, sensitive reads and step-up verification.
- [Audit and logging](docs/tools/audit-and-logging.md) — the audit trail, its sinks and the operational log.
- [Runtime configuration](docs/tools/runtime-configuration.md) — stores, definitions and access changed in code for one request.
- [Schema switches](docs/tools/schema-switches.md) — identity, key types, dedicated stores, soft deletes, JSON translations, the definitions table and the schema file.
- [Audit queries](docs/tools/audit-queries.md) — reading the audit trail from PHP, gated by `view_audit`.
- [Tenancy](docs/tools/tenancy.md) — tenant column or database per tenant, the discriminator, and the stancl and spatie adapters.
- [Models and factories](docs/tools/models-and-factories.md) — Eloquent models and factories for tests, seeding and admin screens.
- [Caching](docs/tools/caching.md) — per-scope bags, tenant-safe keys, generation tokens and stale-while-revalidate.
- [History](docs/tools/history.md) — every value by version, rollback and diffs, and retention.
- [Events](docs/tools/events.md) — every event, when it is dispatched, and the built-in listeners.
- [Alerts](docs/tools/alerts.md) — notifications on changes and refusals, by rule, throttled, never with a value.
- [Approvals](docs/tools/approvals.md) — four-eyes change requests: proposing, approving, stale and expired requests.
- [Import and export](docs/tools/import-export.md) — portable JSON documents, secrets modes, dry runs and store diffs.
- [Importers](docs/tools/importers.md) — moving in from spatie/laravel-settings, toolkit's JSON store and akaunting/laravel-setting.
- [Re-encryption and rescoping](docs/tools/reencryption.md) — after an `APP_KEY` rotation, and after renaming a morph alias.
- [Persisted configuration](docs/tools/persisted-configuration.md) — stores, layers and definitions kept in the database.
- [REST API](docs/tools/rest-api.md) — the opt-in JSON API: endpoints, ETags, approvals, problem details and idempotency.
- [REST API reference](docs/tools/rest-api-reference.md) — every route: ability, request, response, status codes and problem types.
- [Typed settings](docs/tools/typed-settings.md) — typed classes over the key-value engine, and settings migrations.

### Recipes

- [Ship defaults with the application](docs/recipes/shipped-defaults.md) — a versioned defaults file instead of `.env`.
- [Test code that uses settings](docs/recipes/testing-with-the-fake.md) — `Settings::fake()` and its assertions.
- [Act as a user](docs/recipes/acting-as-a-user.md) — authorize and audit changes made on a user's behalf.
- [Move from files to the database](docs/recipes/moving-from-files-to-the-database.md) — bootstrap mode during install, then promotion.
- [Seed the default categories](docs/recipes/seeding-default-categories.md) — the shipped set, your own set, and per tenant.
- [Warm the cache after a deploy](docs/recipes/warming-the-cache.md) — warm, and reset after changing rows by hand.
- [Roll back a setting](docs/recipes/rolling-back-a-setting.md) — find a version, compare it, return to it.
- [Listen to settings events](docs/recipes/listening-to-settings-events.md) — your own listeners, and reading the new value.
- [Approve a change](docs/recipes/approving-a-change.md) — require a second person, then review and apply.
- [Call the REST API with Sanctum](docs/recipes/calling-the-rest-api-with-sanctum.md) — a token with an ability ceiling, ETags and idempotency.
- [Migrate from spatie/laravel-settings](docs/recipes/migrating-from-spatie-laravel-settings.md) — import the values and keep typed classes.
- [Build your own admin UI on the REST API](docs/recipes/building-an-admin-ui-on-the-rest-api.md) — forms from definitions, ETag saves, history and activity.
- [Read settings from an SPA or a mobile app](docs/recipes/consuming-from-an-spa-or-mobile-app.md) — cookies or tokens, ETags and safe retries.
- [Build a per-user preferences screen](docs/recipes/building-a-preferences-screen.md) — defaults, `many()`, `setMany()` and resetting.
- [Set up multi-tenancy](docs/recipes/setting-up-multi-tenancy.md) — a tenant column or a database per tenant.
- [Audit a change](docs/recipes/auditing-a-change.md) — who, when, why, and the value before.
- [Move settings between environments](docs/recipes/moving-settings-between-environments.md) — export, diff and a dry-run import.
- [Use typed settings](docs/recipes/using-typed-settings.md) — a typed class over a group of keys.

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md) (`composer test` runs the suite) and
[SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE).
