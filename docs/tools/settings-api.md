# Settings API

The `Settings` contract, `Simtabi\Laranail\Settings\Contracts\Settings`, reads and writes settings; the `Settings` facade proxies it for applications.

## Handles

`store()`, `for()`, `locale()`, `as()`, `asSystem()`, `asCurrentActor()` and `because()` each return a new handle. The shared instance never changes, so a handle scoped to one owner or actor cannot leak into another request.

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
| `as(Authenticatable\|Actor $actor)` | handle | every operation is authorized and audited; see [Access control](access-control.md) |
| `asSystem(?string $reason)` | handle | trusted code, explicitly; allowed when an actor is required |
| `asCurrentActor(?Request $request)` | handle | the actor the configured resolver finds; `ActorRequired` when nobody is authenticated |
| `because(string $reason)` | handle | the reason recorded with each change in the audit trail |
| `actor()` | `?Actor` | the handle's actor, or null for trusted code |
| `configuration()` | `RuntimeConfiguration` | changes for this request; see [Runtime configuration](runtime-configuration.md) |
| `withConfiguration($change, $run)` | `$run`'s result | changes for one callback, restored afterwards |

## Reading

| Method | Returns | When nothing is stored |
|---|---|---|
| `get($key, $default = null)` | the value | the definition default, else `$default` |
| `lookup($key)` | `Lookup` | `isFound()` is false |
| `has($key)` | `bool` | false |
| `string()`, `integer()`, `float()`, `boolean()`, `array()` | the typed value | throws `TypeMismatch` |
| `explain($key)` | `Explanation` | lists every step tried; redacted when the actor may not view the value |

A stored `null` is an answer: `has()` is true and `get()` returns `null`, not the default.

## Writing

| Method | Effect |
|---|---|
| `set($key, $value)` | validates, casts, encrypts if defined so, and writes to the first writable layer |
| `forget($key)` | removes this handle's own value, so reads fall through again |
| `lock($key)` / `unlock($key)` | refuses or allows further writes to this row |
| `flush()` | removes every value of the handle's owner in its store (needs `for($owner)`) |

## Store-level operations

| Method | Does |
|---|---|
| `preload($owners)` | loads many owners' settings in one read per store; see [Database](database.md) |
| `categories()` | the store's categories; see [Categories](categories.md) |
| `promote(?PromotionRequest)` | moves runtime-file values into the database; returns a `PromotionReport` |
| `clearCache()` | resets the cached values of the handle's owner, or of its whole store; see [Caching](caching.md) |
| `warmCache()` | loads the handle's scope (owner or global) into the cache now; returns the record count |
| `history()` | the value history of the handle's store, owner and locale (needs `view_history`); see [History](history.md) |
| `rollback($key, $toVersion)` | writes the value of an earlier version as a new version (needs `rollback`); returns the new version |
| `historyDiff($key, $from, $to)` | the value at two versions, decrypted only for an actor holding `view_sensitive` |
| `propose($key, $value)` | opens a change request for a value instead of writing it; returns the `ChangeRequest`; see [Approvals](approvals.md) |
| `approvals()` | the store's change requests: list, approve, reject, cancel |
| `export(ExportMode)` | the store as a portable document; see [Import and export](import-export.md) |
| `import($document)` / `previewImport($document)` | write a document through the write pipeline, or report what that would do |
| `compare($document)` | how the store differs from a document, by identity |
| `persistedConfiguration()` | stores, layers and definitions kept in the database; see [Persisted configuration](persisted-configuration.md) |

Writes raise `UnknownSetting` (strict store, no definition), `OwnerNotAllowed`, `LockedSetting`, `InvalidSettingValue`, `EncryptionUnavailable` or `StoreNotWritable`. A handle with an actor can also raise `AccessDenied` or `VerificationRequired`, any handle `ActorRequired` (when an actor is required) and, for a setting that needs approval on a store that cannot hold change requests, `ApprovalUnavailable` (on one that can, the write opens a change request instead). `history()`, `rollback()` and `historyDiff()` raise `HistoryUnavailable` for a store that keeps no history or a version it does not hold. Every exception extends `SettingsException`.

Each write is one action in `Simtabi\Laranail\Settings\Actions` (`SetSetting`, `ForgetSetting`, `LockSetting`, `UnlockSetting`), taking a DTO and the actor. The handle, the console commands and the [REST API](rest-api.md) all delegate to them, so behaviour cannot differ between them. [Typed settings](typed-settings.md) classes read and write through a handle.

## Keys

Keys are lower-case dot notation of `[a-z0-9_]` segments, at most 191 characters. They are lower-cased on input, so `Site.Name` and `site.name` are one key.

---

[← Docs index](../../README.md#documentation)
