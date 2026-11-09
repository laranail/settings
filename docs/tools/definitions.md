# Definitions

A definition says what a key is: its type, default, rules, flags and who may override it.

## Declaring

In code, from a service provider's `boot()`:

```php
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;

app(DefinitionRegistry::class)->define('mail.from.address')
    ->ofType('string')
    ->withDefault('hello@example.com')
    ->rules(['required', 'email'])
    ->labelled('From address')
    ->register();
```

Or in `config/laranail/settings.php` under `definitions`:

```php
'definitions' => [
    'limits.seats' => ['type' => 'int', 'default' => 3, 'rules' => ['integer', 'min:1']],
],
```

## Attributes

| Builder method | Config key | Effect |
|---|---|---|
| `inStore()` | `store` | the store the key belongs to (default store otherwise) |
| `ofType()` | `type` | a caster alias: `string`, `int`, `float`, `bool`, `array`, `datetime`, `enum:<alias>` or a registered one |
| `withDefault()` | `default` | the last fallback of every read |
| `rules()` | `rules` | Laravel validation rules, applied to the raw value before casting |
| `encrypted()` | `encrypted` | stored as ciphertext; needs an application key |
| `public()` / `private()` | `public` | whether the value may leave the server; cannot be combined with `encrypted` |
| `sensitive()` | `sensitive` | reading needs `view_sensitive`; implied by `encrypted` (see [Access control](access-control.md)) |
| `requiresVerification()` | `requires_verification` | writes need a recent step-up verification |
| `requiresApproval()` | `requires_approval` | writes need approval; until the database layer ships, every write raises `ApprovalUnavailable` |
| `locked()` | `locked` | refuses every write |
| `localised()` | `localised` | allows per-locale values; other keys refuse a locale |
| `inherits()` / `doesNotInherit()` | `inherits` | lets owners fall back to parents and the global value |
| `overridableBy()` | `overridable_by` | owner aliases that may hold their own value |
| `precedence()` | `precedence` | `store` (layer order), `file` (a shipped file wins, writes refused) or `config` |
| `labelled()`, `described()`, `inCategory()`, `inGroup()` | `label`, `description`, `category`, `group` | metadata for people and interfaces |

## Types are aliases

A stored type is always an alias resolved by `CasterRegistry`, never a class name, so data cannot make the package instantiate a class. Register an enum or a custom caster under an alias:

```php
app(CasterRegistry::class)->registerEnum('plan', Plan::class); // type 'enum:plan'
```

## Strict stores

A store with `strict => true` refuses reads and writes of keys that have no definition.

---

[← Docs index](../../README.md#documentation)
