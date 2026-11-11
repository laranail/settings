# Getting started

Read and write settings, give them owners, and describe them with definitions.

`laranail/settings` keeps key-value settings for any Laravel owner (the application, a user, a
tenant, a product), stored in layered stores that work even before the database exists, and after
it does. This page
takes a fresh application from install to its first owner and definition.

## Install and migrate

```bash
php artisan laranail::settings.install
php artisan migrate
```

The default store keeps values in the database, above the JSON runtime file and the shipped
defaults. An application without a database drops `database` from the store's layers; see
[Installation](installation.md).

## Read and write

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Settings::set('site.name', 'Acme');
Settings::get('site.name');            // 'Acme'
Settings::get('site.tagline', 'Hi');   // 'Hi' while nothing is stored
Settings::forget('site.name');
```

Classes take the `Simtabi\Laranail\Settings\Contracts\Settings` contract through their constructor; the facade is for application code that prefers it.

## Ship defaults

Put defaults in `settings/defaults.php`; written values override them and `forget()` returns to them. See [Ship defaults with the application](recipes/shipped-defaults.md).

## Give settings owners

Add a store for user settings in `config/laranail/settings.php`:

```php
'stores' => [
    'settings'      => ['layers' => ['database', 'runtime', 'shipped'], 'owners' => []],
    'user_settings' => ['layers' => ['database'], 'owners' => ['user']],
],
```

A store configured before `install` gets its table from the package migration. One added
later gets its own: `php artisan laranail::settings.make-store user_settings --owner=user`,
then migrate. Give the model a morph alias (`Relation::enforceMorphMap(['user' => User::class])`),
implement `Settable` and use `HasSettings` (see [Owners and inheritance](tools/owners.md)), then:

```php
$user->settings()->set('ui.theme', 'dark');
$user->settings()->get('ui.theme', 'light');

User::query()->withSettings()->paginate();   // one query per store for the whole page
```

Deleting the user deletes its settings.

## Describe settings

```php
app(DefinitionRegistry::class)->define('limits.seats')
    ->ofType('int')->withDefault(3)->rules(['integer', 'min:1'])
    ->register();
```

Definitions add types, defaults, validation, encryption, locks and inheritance. See [Definitions](tools/definitions.md).

## Act on a user's behalf

A call without an actor is trusted application code, audited as `system`. Name the actor when a
person is behind the change, and the call is authorized against the `laranail-settings.<ability>`
gates and audited with the reason:

```php
Settings::as($request->user())->because('INC-123')->set('mail.from.address', 'ap@acme.test');
```

Define the gates first (`Gate::define('laranail-settings.update', …)`): an ability nobody defined is
denied. See [Act as a user](recipes/acting-as-a-user.md) and [Access control](tools/access-control.md).

## Check it

```bash
php artisan laranail::settings.doctor          # tables, columns, encryption and stored data
php artisan laranail::settings.show site.name  # where one value comes from
```

When something does not resolve as expected, [Troubleshooting](troubleshooting.md) lists the common
errors and what to run.

## Where to go next

- [Typed settings](tools/typed-settings.md) for a class over a group of keys.
- [REST API](tools/rest-api.md), off by default, for an admin UI or a mobile client; the
  [REST quick start](recipes/rest-api-quick-start.md) gets a first request through.
- [Caching](tools/caching.md), [History](tools/history.md) and [Approvals](tools/approvals.md) for
  production use.
- [Tenancy](tools/tenancy.md) before the first migration of a multi-tenant application.

---

[← Docs index](../README.md#documentation)
