# laranail/settings

No badges yet: the package is not on a registry and its repository has no CI runs to report.
They are added once each claim is true.

> Key-value settings for any Laravel owner (the application, a user, a tenant, a product), stored in layered stores that work before the database exists.

Requires PHP 8.4.1+ or 8.5 and Laravel 13. Works on MySQL, PostgreSQL and SQLite; SQL Server is not supported in 0.x.

> **Pre-release.** This is the package skeleton (design phase 0). The settings API, stores and
> drivers arrive in the next phases; see [Architecture](docs/architecture.md).

## Install

```bash
composer require laranail/settings
```

`laranail/*` packages resolve from their GitHub repositories, not Packagist; see
[Installation](docs/installation.md) for the `repositories` entries.

## Quick start guide and usage

### Getting started

Publish the config file (optional; the package runs on its defaults):

```bash
php artisan laranail::settings.install
```

That writes `config/laranail/settings.php`. The only key today is the default store name,
overridable with `LARANAIL_SETTINGS_STORE`.

### Usage

```php
config('laranail.settings.default'); // 'settings'
```

Reading and writing settings (`$settings->get()`, `->for($user)`, stores and categories) lands
with the engine; [Getting started](docs/getting-started.md) tracks what works today, and the
[Documentation](#documentation) index lists every page.

## <a name="documentation"></a>Documentation

The hosted docs live at <https://opensource.simtabi.com/documentation/laranail/settings/>.

### Guides

- [Installation](docs/installation.md) — Composer repositories, requirements and the install command.
- [Getting started](docs/getting-started.md) — what the package does today and what comes next.
- [Configuration](docs/configuration.md) — every key in `config/laranail/settings.php`.
- [Architecture](docs/architecture.md) — stores, layers, owners, categories and why they are shaped this way.
- [Release](docs/release.md) — versioning, tags and how a release is cut.

## Contributing & security

See [CONTRIBUTING.md](CONTRIBUTING.md) (`composer test` runs the suite) and
[SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE).
