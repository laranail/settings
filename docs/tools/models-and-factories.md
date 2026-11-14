# Models and factories

Four Eloquent models, `Setting`, `SettingCategory`, `SettingDefinition` and `SettingAuditEntry`, with factories for tests, seeding and admin screens.

## The models

| Model | Table | Notes |
|---|---|---|
| `Models\SettingCategory` | categories | `parent()`, `children()`, `forStore()` and `central()` scopes |
| `Models\Setting` | one store's values | `Setting::forStore('billing')`, `->inStore('billing')`; `category()`; trashed rows hidden in a soft-deleting store |
| `Models\SettingDefinition` | definitions | `forStore()` and `runtime()` scopes; shared by every tenant |
| `Models\SettingAuditEntry` | the audit trail | `matching(AuditFilter)` scope; no timestamps |
| `Models\SettingHistory` | the value history | `forSetting()` and `matching(HistoryFilter)` scopes |
| `Models\SettingChangeRequest` | change requests | `matching(ChangeFilter)`, `open()` and `overdue()` scopes |

All of them extend `Models\SettingsModel`: ULID keys, the default store's connection unless a
query names another with `on()`, and table names from `tables`. In column-mode
[tenancy](tenancy.md) they are limited to the current tenant and stamp it on create;
`allTenants()` lifts that for maintenance.

The package reads and writes categories, definitions, audit, history and change requests through
these models. Values are the exception: the database driver reads and writes them through the
query builder, because that path runs on every request, and takes the table name from
`Setting::tableFor()`. A
change made through a model skips validation, casting, encryption, locks, authorization and the
audit trail, and `Setting::$value` is the encoded payload, so application code changes settings
and categories through `Settings::`.

## Factories

No Faker is needed.

```php
use Simtabi\Laranail\Settings\Models\Setting;
use Simtabi\Laranail\Settings\Models\SettingCategory;

$general = SettingCategory::factory()->system()->create(['slug' => 'general']);
SettingCategory::factory()->parent($general)->titled('Mail', 'Outgoing mail')->create(['slug' => 'mail']);
SettingCategory::factory()->forStore('billing')->create();

Setting::factory()->key('site.name')->value('Acme')->inCategory($general)->create();
Setting::factory()->owner('user', 42)->key('ui.theme')->value('dark')->locked()->create();
Setting::factory()->forStore('billing')->key('billing.currency')->locale('fr')->value('EUR')->create();
```

`SettingDefinitionFactory` has `forStore()`, `key()` and `system()`; `SettingAuditEntryFactory`
has `action()`, `by()` and `at()`. `value()` encodes as the value pipeline encodes an unencrypted
value, so `Settings::get()` reads it back; an encrypted setting is arranged with `Settings::set()`.

---

[← Docs index](../../README.md#documentation)
