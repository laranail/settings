# Settings API

The `Settings` contract, `Simtabi\Laranail\Settings\Contracts\Settings`, reads and writes settings; the `Settings` facade proxies it for applications.

## Handles

`store()`, `for()` and `locale()` each return a new handle. The shared instance never changes, so a handle scoped to one owner cannot leak into another request.

```php
use Simtabi\Laranail\Settings\Contracts\Settings;

public function __construct(private readonly Settings $settings) {}

$this->settings->get('mail.from.address');
$this->settings->store('user_settings')->for($user)->get('ui.theme', 'light');
$this->settings->locale('fr')->get('site.greeting');
```

| Method | Returns | Notes |
|---|---|---|
| `store(string $name)` | handle | another configured store |
| `for(Settable\|OwnerRef $owner)` | handle | without `store()`, routes to the store whose `owners` list the owner's alias |
| `locale(string $locale)` | handle | normalised (`pt-br` → `pt_BR`) |

## Reading

| Method | Returns | When nothing is stored |
|---|---|---|
| `get($key, $default = null)` | the value | the definition default, else `$default` |
| `lookup($key)` | `Lookup` | `isFound()` is false |
| `has($key)` | `bool` | false |
| `string()`, `integer()`, `float()`, `boolean()`, `array()` | the typed value | throws `TypeMismatch` |
| `explain($key)` | `Explanation` | lists every step tried |

A stored `null` is an answer: `has()` is true and `get()` returns `null`, not the default.

## Writing

| Method | Effect |
|---|---|
| `set($key, $value)` | validates, casts, encrypts if defined so, and writes to the first writable layer |
| `forget($key)` | removes this handle's own value, so reads fall through again |
| `lock($key)` / `unlock($key)` | refuses or allows further writes to this row |

Writes raise `UnknownSetting` (strict store, no definition), `OwnerNotAllowed`, `LockedSetting`, `InvalidSettingValue`, `EncryptionUnavailable` or `StoreNotWritable`. Every exception extends `SettingsException`.

## Keys

Keys are lower-case dot notation of `[a-z0-9_]` segments, at most 191 characters. They are lower-cased on input, so `Site.Name` and `site.name` are one key.

---

[← Docs index](../../README.md#documentation)
