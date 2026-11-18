# Configurator

`Settings::useConfigurator()` registers one class that sets the package up in code, for every request, job, command and migration, with no environment variable and no published config file.

## Register it

Write a class implementing `Simtabi\Laranail\Settings\Contracts\SettingsConfigurator`, and register
it once, from a service provider's `register()`:

```php
namespace App\Settings;

use Simtabi\Laranail\Settings\Configurator\CacheSetup;
use Simtabi\Laranail\Settings\Configurator\HttpSetup;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Configurator\StoreSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;

final class AcmeSettings implements SettingsConfigurator
{
    public function __construct(private readonly BillingPolicy $policy) {}

    public function configure(SettingsSetup $settings): void
    {
        $settings
            ->tables(prefix: 'acme_')
            ->store('teams', fn (StoreSetup $store) => $store->layers('database', 'runtime')->owners('team')->inherits())
            ->cache(fn (CacheSetup $cache) => $cache->store('redis')->ttl(600))
            ->authorizeUsing($this->policy)
            ->define('site.name', fn (DefinitionBuilder $definition) => $definition->ofType('string')->withDefault('Acme'))
            ->http(fn (HttpSetup $http) => $http->enabled()->prefix('internal/settings'));
    }
}
```

```php
// App\Providers\AppServiceProvider::register()
Settings::useConfigurator(\App\Settings\AcmeSettings::class);
```

- **One per application.** A second registration, from anywhere, throws `SettingsMisuse` naming both
  call sites, and is reported. Compose what several places need inside one `configure()`.
- **From `register()`.** Registering from `boot()` or from a deferred provider throws, naming the
  call site: by then some services may already be built.
- **Built through the container**, so it can take constructor dependencies, including bindings from
  providers registered after the one that registers it.
- `Settings::configuratorRegistration()` returns the class, its file and the call site.

## When it runs

Registering only records the class and the call site. The package runs the configurator in an
`$app->booting()` callback: after every provider has registered, before any provider boots and
before any of the package's services is built. Requests, queue workers, Artisan, migrations and
`config:cache` all pass through it. An exception from `configure()`, or an invalid value, stops the
boot with the configurator's class and call site in the message.

Values that can live in config (table names, stores, layers, cache, tenancy and the rest) are
written into `laranail.settings.*`, so the migration, the commands and `config:cache` read them.
Behaviour that cannot (closures, objects, classes to build) goes to the package's seams.

**With config cached**, the configurator still runs, but its config values are not written: the
cached ones, captured when `config:cache` ran the same configurator, stand. So read a per-deployment
value from your own config file (`config('app.…')`), never with `env()`, which answers null outside
config files once config is cached. Behaviour (drivers, authorizers and the rest) applies either way.

The setup **seals** once the application has booted. Changing it after that, through a setup kept
from `configure()`, throws `SettingsMisuse` and is reported. To change configuration for one
request, use [runtime configuration](runtime-configuration.md).

## Precedence

Lowest to highest; each source overrides only the keys it sets.

1. The shipped defaults in `config/settings.php`.
2. The application's published config file.
3. Package, then module contributors (below), in loose mode only.
4. The application's configurator. Contributors and the application never overwrite each other.
5. [Persisted configuration](persisted-configuration.md), for stores and layers only.
6. A request's [runtime configuration](runtime-configuration.md) (`configuration()`).
7. [Runtime overrides](runtime-overrides.md), which change values, not configuration.

## Packages and modules

A package or a module contributes its own stores, layers and other entries through a contributor,
registered from its own provider's `register()` with `Settings::register()`, which also accepts the
application's configurator. The contract the class implements says who it is:

| Contract | Registered from | Identity |
|---|---|---|
| `Contracts\PackageSettingsContributor` | the package's service provider | its Composer package name, from the file the class lives in |
| `Contracts\ModuleSettingsContributor` | the module's service provider | the name its static `moduleName()` returns |
| `Contracts\SettingsConfigurator` | the application's provider; exactly one | `app` |

A class registered twice, or two contributors with one identity, throw, naming both call sites.

**Modes.** The application chooses with `$settings->mode(ConfiguratorMode::Strict)`. In `Loose`, the
default, packages apply first, then modules, then the application. In `Strict` only the application
applies: each contributor is listed, its values kept for the record, and reported as refused.

**Ownership.** Every name has one owner: a store or a layer, a driver, a caster alias, a definition
key, a macro, or any other configuration path. A second declaration from anyone else throws
`InvalidConfiguration` naming both owners, whoever runs later; there is no last-writer rule.
Decorators, value stages, listeners, audit sinks and doctor checks add up instead, in the order above.

**The application's alone.** Tables, tenancy, HTTP, the default store, the authorizer, the actor
resolver, `requireActor()`, the mode and exclusions. A contributor calling one throws, naming itself.

**Exclusion.** The application can leave a contributor out, on the record, and take its names:
`$settings->exclude(package: 'acme/billing')` or `->exclude(module: 'Billing')`.

## Setup methods

### Settings decisions

| Method | Sets |
|---|---|
| `defaultStore($name)` | `default`; application only |
| `bootstrap($installing = true)` | `bootstrap`: skip the database layer while installing; compute it from local evidence, for example `file_exists(storage_path('app/installing'))` |
| `promote($mode)` | `promote`: `manual`, `auto` or null for every store |
| `tables(prefix:, categories:, audit:, definitions:, history:, changeRequests:, stores:)` | `tables.*`; application only |
| `store($name, fn (StoreSetup $store) => …)` | `stores.<name>.*` |
| `layer($name, fn (LayerSetup $layer) => …)` | `layers.<name>.*` |
| `cache(fn (CacheSetup $cache) => …)` | `cache.*` |
| `tenancy(fn (TenancySetup $tenancy) => …)` | `tenancy.*`; application only |
| `seed(fn (SeedSetup $seed) => …)` | `seed.*` |
| `values(fn (ValuesSetup $values) => …)` | `values.*` and `writes.*` |
| `http(fn (HttpSetup $http) => …)` | `http.*`; application only |
| `audit(fn (AuditSetup $audit) => …)` | `audit.*` and `history.*` |
| `approvals(fn (ApprovalsSetup $approvals) => …)` | `approvals.*` |
| `logging(fn (LoggingSetup $logging) => …)` | `logging.*` |
| `alerts(fn (AlertsSetup $alerts) => …)` | `alerts.*` |
| `persistedConfiguration($enabled = true)` | `configuration.persisted` |
| `mode(ConfiguratorMode $mode)` | who may contribute; application only |
| `exclude(package:, module:)` | leave one contributor out; application only |

| Sub-setup | Methods |
|---|---|
| `StoreSetup` | `layers()`, `owners()`, `strict()`, `inherits()`, `bootstrap()`, `promote()`, `table()`, `softDeletes()` |
| `LayerSetup` | `driver()`, `connection()`, `disk()`, `path()`, `writable()` |
| `CacheSetup` | `enabled()`, `store()`, `prefix()`, `ttl()`, `stale()` |
| `TenancySetup` | `none()`, `column()`, `database()`, `discriminator()` |
| `SeedSetup` | `enabled()`, `stores()` |
| `ValuesSetup` | `maxBytes()`, `retryOnConflict()` |
| `HttpSetup` | `enabled()`, `prefix()`, `middleware()`, `rateLimit()`, `pageSize()` |
| `AuditSetup` | `keepDays()`, `history()` |
| `ApprovalsSetup` | `minApprovals()`, `allowSelf()`, `expireAfter()`, `keepDays()` |
| `LoggingSetup` | `level()`, `channel()` |
| `AlertsSetup` | `enabled()`, `throttle()` |

Every setter validates where it is called; an invalid value throws `InvalidConfiguration` naming the
method and the config path, for example `cache()->ttl() (laranail.settings.cache.ttl)`.

### Behaviour

Each of these reaches an existing seam rather than config, for every request, and still applies with
config cached. Each is used ahead of config, and a request's `configuration()` still wins over it.

| Method | Does |
|---|---|
| `driver($name, fn (LayerConfig $layer) => …)` | a custom storage driver layers can name; see [Drivers and layers](drivers.md) |
| `caster($alias, Caster $caster)` | a type definitions can name |
| `importer($name, ImporterClass::class)` | an importer `import` can name; see [Importers](importers.md) |
| `define($key, fn (DefinitionBuilder $definition) => …)` | a setting's definition, in code; see [Definitions](definitions.md) |
| `authorizeUsing($authorizer)` | an `Authorizer`, a closure or a class to build; application only |
| `verifyUsing($verifier)` | a `Verifier`, a closure or a class to build |
| `resolveActorsUsing($resolver)` | an `ActorResolver`, a closure or a class to build; application only |
| `requireActor($required = true)` | `authorization.require_actor`; application only |
| `auditTo(...$sinks)` | audit sinks added to the configured ones; see [Audit and logging](audit-and-logging.md) |
| `logTo($logger)` | the operational log's destination, or `false` for none |

### Composition

| Method | Does |
|---|---|
| `decorate(fn (Settings $settings) => new MyDecorator($settings))` | wraps the settings; the decorator extends `Configurator\SettingsDecorator`, which forwards every call and wraps each handle `store()`, `for()`, `as()` and the others return, so it survives a chain; the last added wraps outermost |
| `pipe(Stage::BeforeWrite, fn ($value, Key $key) => …)` | a value stage on the caller's value, before validation, casting and encryption |
| `pipe(Stage::AfterRead, fn ($value, Key $key) => …)` | a value stage on the read value, after decryption and casting, before redaction; a stage never sees ciphertext |
| `on(SettingSaved::class, $listener)` | a listener on one of the package's [events](events.md) |
| `macro($name, $closure)` / `mixin($object)` | methods on every handle; macros are static, so they last for the process |
| `doctorCheck($check)` | a `DoctorCheck` that `laranail::settings.doctor` runs after its own |

```php
final class TracingSettings extends SettingsDecorator
{
    public function get(string $key, mixed $default = null): mixed
    {
        Log::debug('settings read', ['key' => $key]);

        return parent::get($key, $default);
    }
}

$settings->decorate(fn (Settings $settings) => new TracingSettings($settings));
```

## Seeing it

`php artisan laranail::settings.configurator` prints the configurator (class, file and call site),
every contributor with its status (applied, refused in strict mode, or excluded by the application,
with where), and each configuration key with its value, secrets redacted, and its source: `default`,
`config`, `configurator` with the owner, or `persisted`. `--key=tables`, `--owner=acme/billing` and
`--scope=package` narrow it; `--json` is for scripts.

`laranail::settings.doctor` reports whether the setup ran, warns about each refused or excluded
contributor, and warns about any `LARANAIL_SETTINGS_*` variable still set, naming the method that
replaces it (see [Upgrading](../upgrading.md#environment-variables)).

---

[← Docs index](../../README.md#documentation)
