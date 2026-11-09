# Getting started

Read and write settings, give them owners, and describe them with definitions.

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
    'settings'      => ['layers' => ['runtime', 'shipped'], 'owners' => []],
    'user_settings' => ['layers' => ['runtime'], 'owners' => ['user']],
],
```

Implement `Settable` on the model (see [Owners and inheritance](tools/owners.md)), then:

```php
Settings::for($user)->set('ui.theme', 'dark');
Settings::for($user)->get('ui.theme', 'light');
```

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
| Database | the `settings` and `settings_categories` tables, `HasSettings`, bootstrap before the database, promotion |
| Performance | caching with stale-while-revalidate |
| Governance | history and rollback, export and import, `doctor` |
| Typed classes | strongly typed settings classes over the same engine |

---

[← Docs index](../README.md#documentation)
