# Settings API

Every public method of the `Settings` contract, `Simtabi\Laranail\Settings\Contracts\Settings`, with its signature, an example, the exceptions it throws and the events it fires; then the facade, the `settings()` helper, `HasSettings` and the objects the handle returns.

The package is headless: this contract, the [REST API](rest-api.md) and the [commands](commands.md) are the three ways in, and all three run the same actions, so a capability reached from one behaves the same from the others.

## Getting a handle

Take the contract in a constructor (the package's own classes do), or use the facade or the helper in application code.

```php
use Simtabi\Laranail\Settings\Contracts\Settings;

final readonly class InvoiceMailer
{
    public function __construct(private Settings $settings) {}

    public function sender(): string
    {
        return $this->settings->string('mail.from.address');
    }
}
```

The contract is bound `scoped`: one instance per request or job, never shared across Octane requests. Every scoping method returns a **new handle**; the shared instance never changes, so a handle scoped to one owner or actor cannot leak into another call.

```php
$settings->store('user_settings')->for($user)->locale('fr')->as($admin)->because('INC-7')->get('ui.theme');
```

## Every method at a glance

| Group | Methods |
|---|---|
| Scoping (return a handle) | [`store()`](#store), [`for()`](#for), [`locale()`](#locale), [`as()`](#as), [`asSystem()`](#assystem), [`asCurrentActor()`](#ascurrentactor), [`because()`](#because) |
| Reading | [`get()`](#get), [`lookup()`](#lookup), [`has()`](#has), [`missing()`](#missing), [`many()`](#many), [`all()`](#all), [`string()`, `integer()`, `float()`, `boolean()`, `array()`](#typed-getters), [`explain()`](#explain), [`definitions()`](#definitions) |
| Writing | [`set()`](#set), [`setMany()`](#setmany), [`remember()`](#remember), [`forget()`](#forget), [`forgetMany()`](#forgetmany), [`lock()`, `unlock()`](#lock-and-unlock), [`flush()`](#flush) |
| Cache and loading | [`preload()`](#preload), [`warmCache()`](#warmcache), [`clearCache()`](#clearcache) |
| History | [`history()`](#history), [`historyDiff()`](#historydiff), [`rollback()`](#rollback) |
| Approvals | [`propose()`](#propose), [`approvals()`](#approvals) |
| Store administration | [`categories()`](#categories), [`promote()`](#promote), [`audit()`](#audit), [`export()`](#export), [`import()`, `previewImport()`](#import-and-previewimport), [`compare()`](#compare), [`persistedConfiguration()`](#persistedconfiguration) |
| Context | [`actor()`](#actor), [`configuration()`](#configuration), [`withConfiguration()`](#withconfiguration) |

Abilities are named in the [access control](access-control.md) reference. A handle **without** an actor is trusted code: it is not authorized, and it is audited as `system` (unless `authorization.require_actor` is on, when it throws `ActorRequired`). A handle **with** an actor is authorized for every operation, reads included, and every refusal fires `AccessWasDenied`.

Every exception extends `Simtabi\Laranail\Settings\Exceptions\SettingsException`; the [exceptions reference](exceptions.md) says when each is raised and how the REST API answers it.

## Scoping

### `store()`

```php
public function store(string $name): static

$settings->store('user_settings')->get('ui.theme');
```

A handle on another configured store. Throws `UnknownStore` on first use when the store is not configured, `InvalidIdentifier` for a malformed name. Fires nothing.

### `for()`

```php
public function for(Settable|OwnerRef $owner): static

$settings->for($user)->set('ui.theme', 'dark');
$settings->for(new OwnerRef('team', 42))->get('billing.plan');
```

A handle for one owner. Without `store()` it routes to the store whose `owners` list the owner's morph alias; with one, the store must accept the alias. A `Settable` model also brings its parents, so reads can fall back to them (see [Owners](owners.md)). Throws `OwnerNotAllowed` when no store accepts the alias, `InvalidIdentifier` for a class-name alias.

### `locale()`

```php
public function locale(string $locale): static

$settings->locale('fr')->get('site.greeting');   // falls back to app.fallback_locale, then to no locale
```

A handle reading and writing one locale, normalised (`pt-br` becomes `pt_BR`). Throws `InvalidIdentifier` for a malformed locale.

### `as()`

```php
public function as(Authenticatable|Actor $actor): static

$settings->as($request->user())->set('mail.from.address', 'ap@acme.test');
```

A handle acting as a user (or a prepared `Actor`): every operation is authorized through the configured authorizer and audited with that actor. See [Act as a user](../recipes/acting-as-a-user.md).

### `asSystem()`

```php
public function asSystem(?string $reason = null): static

$settings->asSystem('nightly sync')->set('feeds.last_run', now()->toIso8601String());
```

Trusted code, said out loud: allowed even when `authorization.require_actor` is on, audited as `system` with the reason.

### `asCurrentActor()`

```php
public function asCurrentActor(?Request $request = null): static

$settings->asCurrentActor()->get('ui.theme');
```

A handle acting as whoever the configured actor resolver (`authentication.resolver`) finds for the request: the guard's user, or a Sanctum token with its abilities as a ceiling. Throws `ActorRequired` when nobody is authenticated.

### `because()`

```php
public function because(string $reason): static

$settings->as($admin)->because('INC-123')->set('billing.grace_days', 14);
```

A handle whose changes carry this reason in the audit trail, history and change requests.

## Reading

Reads fire no event (they are the hot path), except `AccessWasDenied` when an actor is refused. A read by an actor needs `view`; a sensitive or encrypted value needs `view_sensitive` as well. On a strict store a key without a definition throws `UnknownSetting`.

### `get()`

```php
public function get(string $key, mixed $default = null): mixed

$settings->get('site.name');                  // the stored value
$settings->get('site.tagline', 'Welcome');    // the definition default, else 'Welcome'
```

The value, resolved through the store's layers, the owner's parents and the global value as the definition allows; when nothing is found, the definition's default, else `$default`. A stored `null` is an answer: `get()` returns `null`, not the default. Throws `AccessDenied`, `ActorRequired`, `UnknownSetting`, `DriverUnavailable`.

### `lookup()`

```php
public function lookup(string $key): Lookup

$lookup = $settings->lookup('site.name');
$lookup->isFound();      // true when stored or defaulted
$lookup->value();        // throws when not found
$lookup->valueOr('x');
$lookup->layer();        // 'database', 'runtime', 'shipped', 'default' …
$lookup->scope();        // the scope the value came from (an owner, a parent, global)
```

A presence-aware read. Throws as `get()` does.

### `has()`

```php
public function has(string $key): bool

if ($settings->has('mail.from.address')) { … }
```

True when a value is stored (a stored `null` counts) or the definition has a default.

### `missing()`

```php
public function missing(string $key): bool

if ($settings->for($user)->missing('onboarding.completed_at')) {
    return redirect()->route('onboarding');
}
```

The opposite of `has()`.

### `many()`

```php
public function many(array $keys): array

[$name, $tagline] = array_values($settings->many(['site.name', 'site.tagline' => 'Welcome']));
// ['site.name' => 'Acme', 'site.tagline' => 'Welcome']
```

Several values at once, keyed as asked: a list of keys, `key => default` pairs, or both. Each key is read and authorized exactly as `get()` reads it. Throws `InvalidKey` when a list entry is not a string, and whatever `get()` throws.

### `all()`

```php
public function all(?string $prefix = null): array

$settings->for($user)->all();          // ['ui.density' => 'compact', 'ui.theme' => 'dark']
$settings->all('mail');                // 'mail' itself and every key below it
```

Every value the handle can see in its store and scope, by key, in key order: each key the scope holds in any layer, plus each key a code or config definition of the store names (so defaults appear). A key the actor may not view, or a sensitive key whose value it may not read, is left out rather than masked; a strict store's undefined stray rows are left out too. This is the PHP form of the REST API's `GET …/settings` listing, and both walk the same keys. Parents are consulted for the values of those keys, but keys held **only** by a parent are not listed unless a definition names them.

### Typed getters

```php
public function string(string $key): string
public function integer(string $key): int
public function float(string $key): float     // accepts a stored int
public function boolean(string $key): bool
public function array(string $key): array

$seats = $settings->integer('limits.seats');
```

The value, guaranteed to be of that type. Throws `TypeMismatch` when the value is of another type or when nothing is found.

### `explain()`

```php
public function explain(string $key): Explanation

$explanation = $settings->for($user)->explain('ui.theme');
$explanation->lookup;     // the answer, as lookup() returns it
$explanation->steps;      // every layer and scope tried, in order, as text
```

How a read resolves: every step tried and the answer. For an actor without `view_sensitive` on a sensitive key, the answer is redacted (`isRedacted()`), the steps are kept.

### `definitions()`

```php
public function definitions(): array   // list<Definition>

foreach ($settings->as($admin)->definitions() as $definition) {
    $definition->key->value;    // 'mail.from.address'
    $definition->type;          // 'email', 'integer', …
    $definition->label;         // for a form
    $definition->category;      // the category slug
    $definition->isSensitive();
}
```

The store's definitions from code, configuration and the definitions table, in key order: what a UI renders a form from. Never a value; an encrypted definition's default stays out. Needs `view`; throws `AccessDenied`. The PHP form of `GET /stores/{store}/definitions`.

## Writing

A write by an actor needs the ability named below, and step-up verification when `verification.abilities` lists it or the definition `requiresVerification()` (`VerificationRequired`, with `VerificationWasRequired` fired). A write to a setting that needs approval opens a change request instead (`ChangeRequested`), and the method still returns normally; on a store that cannot hold change requests it throws `ApprovalUnavailable`.

### `set()`

```php
public function set(string $key, mixed $value): void

$settings->set('site.name', 'Acme');
$settings->for($user)->set('ui.theme', 'dark');
```

Validates against the definition's rules, casts, encrypts an encrypted definition, and writes to the first writable layer, recording history and audit in the same transaction. Needs `update`.

- **Throws** `UnknownSetting` (strict store), `OwnerNotAllowed`, `LockedSetting`, `InvalidSettingValue`, `EncryptionUnavailable`, `StoreNotWritable`, `ConcurrentModification` (after the one retry `writes.retry_on_conflict` allows), `AccessDenied`, `ActorRequired`, `VerificationRequired`, `ApprovalUnavailable`.
- **Fires** `SettingSaving`, then `SettingSaved` after commit; `ChangeRequested` instead when approval is needed.

### `setMany()`

```php
public function setMany(array $values): void

$settings->for($user)->setMany([
    'ui.theme'   => 'dark',
    'ui.density' => 'compact',
]);
```

One `set()` per key, in the order given. **Not atomic**: each write is its own transaction, and the first refusal stops the rest while earlier writes stay. Wrap the call in `DB::transaction()` when every value lives in one database store and all-or-nothing matters. Throws and fires as `set()`, per key.

### `remember()`

```php
public function remember(string $key, callable $value): mixed

$installId = $settings->asSystem()->remember('install.id', fn (): string => (string) Str::ulid());
```

The stored value; when nothing is found, `$value()` is written with `set()` and read back, so the answer is cast exactly as a later read would be. Useful for values generated once (an installation id, a signing salt). When the write opens a change request instead, the read falls back to the computed value. Throws and fires as `get()` and `set()`.

### `forget()`

```php
public function forget(string $key): void

$settings->for($user)->forget('ui.theme');   // the user's value goes; reads fall back to the global one
```

Removes this handle's own value (owner, locale), so reads fall through again. Needs `forget`. Throws as `set()` does (`LockedSetting` for a locked row). Fires `SettingForgetting` and `SettingForgotten`.

### `forgetMany()`

```php
public function forgetMany(iterable $keys): void

$settings->for($user)->forgetMany(['ui.theme', 'ui.density']);
```

One `forget()` per key, in the order given; not atomic, like `setMany()`.

### `lock()` and `unlock()`

```php
public function lock(string $key): void
public function unlock(string $key): void

$settings->as($admin)->lock('billing.currency');
```

Refuse, or allow again, further writes to this row. A locked row refuses everyone unless the store's `locks.overridable` is on and the actor holds `override_lock`. Needs `lock` / `unlock`. Fires `SettingLocked` / `SettingUnlocked`.

### `flush()`

```php
public function flush(): void

$settings->for($user)->asSystem('account closed')->flush();
```

Removes every value the handle's owner holds in its store, as when an owner is deleted (`HasSettings` does this for you). Needs `for($owner)` (a `LogicException` otherwise) and `forget`. Throws `AccessDenied`, `StoreNotWritable`. Fires `ScopeFlushing` and `ScopeFlushed`.

## Cache and loading

### `preload()`

```php
public function preload(iterable $owners): void

$settings->preload($users);   // one read per store for the whole page
foreach ($users as $user) {
    $settings->for($user)->get('ui.theme');   // no further query
}
```

Loads many owners' settings, their parents' and the global scope, one read per store, for the rest of the request. `User::query()->withSettings()` does this for a query (see [Database](database.md)).

### `warmCache()`

```php
public function warmCache(): int

$settings->store('settings')->warmCache();   // records cached
```

Loads the handle's scope (owner or global) into the cache now. Returns the number of records cached (0 when the store is not cached). Needs `manage_stores` with an actor. See [Caching](caching.md).

### `clearCache()`

```php
public function clearCache(): void

$settings->for($user)->clearCache();   // that owner's cached values
$settings->clearCache();               // every cached value of the store, every tenant
```

Resets cached values; stored values are untouched. Needs `manage_stores` with an actor.

## History

All three need a store that keeps history (`history.enabled` when it was migrated); otherwise `HistoryUnavailable`. See [History](history.md).

### `history()`

```php
public function history(): HistoryQuery

$entries = $settings->for($user)->history()->key('ui.theme')->since(now()->subWeek())->get();
```

The value history of the handle's store, owner and locale. Filters: `for()`, `global()`, `store()`, `key()`, `locale()`, `since()`, `until()`, `versions($from, $to)`; then `get(?int $limit)`, `cursor()` or `paginate($perPage, $cursor)`. Payloads stay as stored; encrypted or sensitive payloads are removed for an actor without `view_sensitive`. Needs `view_history`.

### `historyDiff()`

```php
public function historyDiff(string $key, int $from, int $to): HistoryDiff

$diff = $settings->as($admin)->historyDiff('mail.from.address', 3, 5);
```

The value at two versions. An encrypted or sensitive value is decrypted only for an actor holding `view_sensitive` (or trusted code), and otherwise redacted to "changed or not". Needs `view_history`.

### `rollback()`

```php
public function rollback(string $key, int $toVersion): int

$newVersion = $settings->as($admin)->because('revert INC-7')->rollback('mail.from.address', 3);
```

Writes the value held at `$toVersion` as a new version, through the normal write pipeline; returns that version. Needs `rollback`, which `verification.abilities` lists by default. Throws `HistoryUnavailable`, `AccessDenied`, `VerificationRequired`, `LockedSetting`. Fires `SettingSaving`, `SettingSaved` and `SettingRolledBack`. See [Roll back a setting](../recipes/rolling-back-a-setting.md).

## Approvals

See [Approvals](approvals.md) and [Approve a change](../recipes/approving-a-change.md).

### `propose()`

```php
public function propose(string $key, mixed $value): ChangeRequest

$request = $settings->as($author)->because('Q3 price change')->propose('billing.price', 49);
$request->id;   // ULID
```

Opens a change request for a value instead of writing it, whether or not the setting needs approval. The value is validated now and stored as the values table would store it. Needs `update`. Throws `ApprovalUnavailable`, `InvalidSettingValue`. Fires `ChangeRequested`.

### `approvals()`

```php
public function approvals(): Approvals

$approvals = $settings->as($reviewer)->approvals();
$open = $approvals->pending()->key('billing.price')->get();
$approvals->approve($open[0]->id, 'checked with finance');
```

The store's change requests, decided as the handle's actor: `pending()`, `status(ChangeStatus)`, `key()`, `for($owner)`, then `get($limit, $after)` or `find($id)`; and `approve($id, $reason)`, `reject($id, $reason)`, `cancel($id, $reason)`, each returning the `ChangeRequest`. Approving needs `approve`; cancelling is the requester's. Throws `UnknownChangeRequest`, `ChangeRequestClosed`, `AccessDenied`. Fires `ChangeApproved`, then `ChangeApplied` (with `SettingSaved`) or `ChangeStale`; `ChangeRejected`; `ChangeCancelled`.

## Store administration

### `categories()`

```php
public function categories(): CategoryManager

$categories = $settings->as($admin)->categories();
$categories->create(new NewCategory('billing', 'Billing', parent: 'general'));
$categories->rename('billing', 'Billing and invoices');
$categories->move('billing', null);
$categories->all();
```

The store's categories: `create(NewCategory)`, `rename()`, `move()`, `delete()`, `get()`, `all()`. Writes need `manage_categories`, reads `view`. Throws `UnknownCategory`, `CategoryCycle`, `CategoryStoreMismatch`. Fires `CategorySaving`, `CategorySaved`, `CategoryRenamed`, `CategoryMoved`, `CategoryDeleting`, `CategoryDeleted`. See [Categories](categories.md).

### `promote()`

```php
public function promote(?PromotionRequest $request = null): PromotionReport

$report = $settings->asSystem('post-install')->promote();
$report->promoted;   // keys copied into the database
```

Copies the values written to the runtime file before the database existed into the database layer; shipped defaults are never copied. Idempotent. Needs `promote`. Throws `PromotionLocked`, `StoreNotWritable`, `AccessDenied`. Fires `StorePromoting` and `StorePromoted`. See [Move from files to the database](../recipes/moving-from-files-to-the-database.md).

### `audit()`

```php
public function audit(): AuditQuery

$page = $settings->as($auditor)->audit()->key('mail.from.address')->since(now()->subMonth())->paginate(50);
```

The audit trail: `for()`, `global()`, `store()`, `key()`, `action(AuditAction)`, `outcome(AuditOutcome)`, `since()`, `until()`; then `get()`, `cursor()` or `paginate()`. Entries never hold values. Needs `view_audit`. See [Audit queries](audit-queries.md) and [Audit a change](../recipes/auditing-a-change.md).

### `export()`

```php
public function export(ExportMode $mode = ExportMode::Redacted): ExportDocument

file_put_contents('settings.json', json_encode($settings->as($admin)->export()->toArray()));
```

The store as a portable document: categories by slug, settings by scope, key and locale. Encrypted values are left out (`Redacted`), kept as ciphertext (`Ciphertext`), or decrypted (`Plaintext`, needs `view_sensitive`). Needs `export`. See [Import and export](import-export.md).

### `import()` and `previewImport()`

```php
public function import(ExportDocument $document): ImportReport
public function previewImport(ExportDocument $document): ImportReport

$document = ExportDocument::fromArray(json_decode(file_get_contents('settings.json'), true));
$preview  = $settings->as($admin)->previewImport($document);   // nothing written
$report   = $settings->as($admin)->because('copy from staging')->import($document);
```

Writes a document through the write pipeline, categories first, in one transaction; `previewImport()` reports what that would do without writing. Locks and system categories in the document are ignored here (the console's `--trusted` honours them). Needs `import` (verified by default). Throws `InvalidImport`, `AccessDenied`, `VerificationRequired`. Fires `SettingSaved` and the category events per write, or `ChangeRequested` for keys that need approval.

### `compare()`

```php
public function compare(ExportDocument $document): StoreDiff

$diff = $settings->as($admin)->compare($document);
$diff->changed;   // keys whose value differs, never the values
```

How the store differs from a document: `added`, `removed`, `changed`, `unchanged`, `unknown`. Needs `export`.

### `persistedConfiguration()`

```php
public function persistedConfiguration(): PersistedConfigurationEditor

$editor = $settings->as($admin)->persistedConfiguration();
$editor->saveStore('tenant_settings', ['layers' => ['database'], 'owners' => ['team']]);
$editor->saveDefinition('billing.plan', ['type' => 'string', 'default' => 'free']);
```

Stores and layers kept in the database, and definitions kept in the definitions table: `entries()`, `saveStore()`, `saveLayer()`, `deleteStore()`, `deleteLayer()`, `saveDefinition()`, `deleteDefinition()`. Needs `manage_stores` or `manage_definitions` and `configuration.persisted`. See [Persisted configuration](persisted-configuration.md).

## Context

### `actor()`

```php
public function actor(): ?Actor

$settings->as($user)->actor()?->id;
```

The handle's actor, or `null` for trusted code.

### `configuration()`

```php
public function configuration(): RuntimeConfiguration

$settings->configuration()->define('beta.enabled')->ofType('boolean')->withDefault(false);
$settings->configuration()->authorizeUsing(fn ($actor, $ability, $subject) => …);
```

Configuration changed in code for the current request only: `addStore()`, `addLayer()`, `extendDriver()`, `define()`, `authorizeUsing()`, `verifyUsing()`, `resolveActorsUsing()`, `auditTo()`, `logTo()`, `requireActor()`, `allowActorless()`. See [Runtime configuration](runtime-configuration.md).

### `withConfiguration()`

```php
public function withConfiguration(callable $change, callable $run): mixed

$value = $settings->withConfiguration(
    fn (RuntimeConfiguration $config) => $config->requireActor(),
    fn () => $settings->as($user)->get('billing.plan'),
);
```

Applies the changes for the duration of `$run` only, then restores them; returns `$run`'s result.

## The facade

`Simtabi\Laranail\Settings\Facades\Settings` proxies every method above (its docblock lists them for IDEs). It is application sugar; the package's own classes never use it.

```php
use Simtabi\Laranail\Settings\Facades\Settings;

Settings::for($user)->get('ui.theme', 'light');
$fake = Settings::fake();   // see Test code that uses settings
```

## The `settings()` helper

A namespaced function, never a global one: a bare global `settings()` is a name other packages and applications already define, and the second definition would silently win.

```php
use function Simtabi\Laranail\Settings\settings;

settings('site.name');                    // get('site.name')
settings('site.tagline', 'Welcome');      // with a default
settings()->for($user)->set('ui.theme', 'dark');   // the handle
```

## `HasSettings`

Makes an Eloquent model an owner. The model implements `Settable` and has a morph map entry.

```php
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Concerns\HasSettings;

final class User extends Authenticatable implements Settable
{
    use HasSettings;

    public function settingsParents(): array
    {
        return OwnerRef::list(['team' => $this->team_id]);   // optional: inherit from the team
    }
}

$user->settings()->get('ui.theme');                    // Settings::for($user)
$user->settings()->as($user)->set('ui.theme', 'dark');
User::query()->withSettings()->paginate();            // preloads every user on the page
```

| Member | Does |
|---|---|
| `settings()` | a handle for this model; rename it with `use HasSettings { settings as preferences; }` |
| `settingsOwner()` | the model as an `OwnerRef` (morph alias and key) |
| `settingsParents()` | owners to inherit from, nearest first; build from foreign keys so no model loads |
| `scopeWithSettings()` | eager-loads the settings of every model a query returns |
| deleting the model | flushes its settings; a soft-deleted model keeps them until force-deleted |

## Typed settings

A typed class reads and writes its properties through a handle, so everything above applies to it. See [Typed settings](typed-settings.md) and [Use typed settings](../recipes/using-typed-settings.md).

```php
$mail = MailSettings::load($settings->as($admin));
$mail->port = 587;
$mail->save();
```

| Member | Does |
|---|---|
| `TypedSettings::load(Settings $settings)` | reads every property's key through the handle |
| `$typed->save(?Settings $settings = null)` | writes the changed properties (all of them to another handle) through `set()` |
| `TypedSettings::register(DefinitionRegistry $registry)` | registers each property as a definition (type, default, encryption); `typed.classes` does it for you |
| `TypedSettings::properties()` | the property-to-key map |

## Keys

Keys are lower-case dot notation of `[a-z0-9_]` segments, at most 191 characters. They are lower-cased on input, so `Site.Name` and `site.name` are one key. A malformed key throws `InvalidKey`.

## How the channels share one implementation

Each write is one action in `Simtabi\Laranail\Settings\Actions` (`SetSetting`, `ForgetSetting`, `LockSetting`, `UnlockSetting`, …), taking a DTO and the actor. The handle, the console commands and the [REST API](rest-api.md) all delegate to them, so behaviour cannot differ between them; `Enums\Capability::actions()` lists which action backs each REST route, and an architecture test holds the three to each other.

---

[← Docs index](../../README.md#documentation)
