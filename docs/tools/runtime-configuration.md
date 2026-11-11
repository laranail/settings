# Runtime configuration

`$settings->configuration()` changes stores, layers, definitions, drivers and access for the current request only.

## Scope

`RuntimeConfiguration` is bound `scoped`: Octane and queue workers flush it between requests and jobs, so nothing set here outlives the request that set it. Store and layer entries merge over `laranail.settings.stores` and `.layers`; the runtime entry wins.

## Methods

| Method | Effect |
|---|---|
| `addStore($name, array $config)` | add or replace a store; the same shape as config |
| `addLayer($name, array $config)` | add or replace a layer |
| `extendDriver($name, Closure $factory)` | a custom driver for this request; built-in names are refused |
| `define($key)` | a definition builder; `register()` adds it, and it wins over the shared registry |
| `authorizeUsing(Authorizer\|Closure)` | replace the authorizer; a closure returns a `Decision`, `true`, `false` or `null` (abstain) |
| `verifyUsing(Verifier\|Closure)` | replace the verifier; a closure returns `bool` |
| `resolveActorsUsing(ActorResolver\|Closure)` | replace the actor resolver |
| `auditTo(AuditSink ...)` | add audit sinks next to the configured ones |
| `logTo(LoggerInterface\|false)` | send the operational log elsewhere, or nowhere |
| `requireActor()` / `allowActorless()` | override `authorization.require_actor` |
| `promote($store, 'manual'\|'auto', ?PromotionSelection $only)` | how a store promotes its runtime file, and optionally what it copies; wins over `stores.<name>.promote` and the global `promote` |

Each change returns the configuration, so calls chain, and each is logged and audited as `configure`.

```php
use Simtabi\Laranail\Settings\Contracts\Settings;

$settings->configuration()
    ->addLayer('memory', ['driver' => 'array'])
    ->addStore('flags', ['layers' => ['memory'], 'owners' => ['team']])
    ->requireActor();
```

## Validation

Store, layer and driver changes are validated exactly like config, by building the stores, before they are accepted. An invalid change raises `InvalidConfiguration` naming the path, and nothing is kept.

## Changes for one callback

`withConfiguration()` applies changes, runs a callback, and restores everything afterwards, even when the callback throws. It returns the callback's result.

```php
$report = $settings->withConfiguration(
    fn ($configuration) => $configuration->auditTo($sink)->requireActor(),
    fn () => $importer->run(),
);
```

Persisted configuration, saved in the database and edited through the API, arrives with the governance release.

---

[← Docs index](../../README.md#documentation)
