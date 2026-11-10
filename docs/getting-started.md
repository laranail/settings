# Getting started

Read and write settings, give them owners, and describe them with definitions.

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

## What comes next

| Phase | Adds |
|---|---|
| Schema switches | uuid/ulid ids, typed owner keys, other identity modes, dedicated stores, soft deletes, translated labels |
| Performance | caching with stale-while-revalidate |
| Governance | history and rollback, approvals, export and import, the full `doctor` |
| Typed classes | strongly typed settings classes over the same engine |

---

[← Docs index](../README.md#documentation)
