# Models and factories

Four Eloquent models, `Setting`, `SettingCategory`, `SettingDefinition` and `SettingAuditEntry`, with factories for tests, seeding and admin screens.

## The models

| Model | Table | Notes |
|---|---|---|
| `Models\SettingCategory` | categories | `parent()`, `children()`, `forStore()` scope; trashed rows hidden |
| `Models\Setting` | one store's values | `Setting::forStore('billing')`, `->inStore('billing')`; `category()`; trashed rows hidden |
| `Models\SettingDefinition` | the optional definitions table | shared by every tenant |
| `Models\SettingAuditEntry` | the audit trail | ULID key, no timestamps |

Table names, key types and connections come from the recorded schema shape, so the models follow
the [schema switches](schema-switches.md): uuid and ulid keys are generated on create. In
column-mode [tenancy](tenancy.md) they are limited to the current tenant and stamp it on create.

The engine never uses them: reads and writes go through the drivers on the query builder. A
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
