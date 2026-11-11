# Configuration

Every key in `config/laranail/settings.php`, read at `config('laranail.settings.*')`.

## Keys

### Stores

| Key | Default | Purpose |
|---|---|---|
| `default` | `settings` (`LARANAIL_SETTINGS_STORE`) | the store used when a call names neither a store nor an owner |
| `stores.<name>.layers` | `['database', 'runtime', 'shipped']` | layer names, in read order; writes go to the first writable one |
| `stores.<name>.owners` | `[]` | morph aliases this store holds values for; each alias belongs to one store |
| `stores.<name>.strict` | `false` | refuse keys without a definition |
| `stores.<name>.inherits` | `false` | let owners fall back to parents and the global value for keys whose definition does not say otherwise |
| `stores.<name>.locks.overridable` | `false` | let an actor allowed `override_lock` write a locked setting |
| `stores.<name>.table` | the store name | the store's values table, after `tables.prefix` |
| `stores.<name>.bootstrap` | `false` | skip the database layer while, and only while, there is evidence of an install in progress |
| `bootstrap` | `false` (`LARANAIL_SETTINGS_BOOTSTRAP`) | evidence of an install in progress; set it for the install window only |
| `stores.<name>.promote` | `manual` | `manual`: copy the runtime file into the database with `laranail::settings.promote`; `auto`: copy it once, the first time the database connection answers and the store's table exists. A string is shorthand for `['mode' => …]` with every value included |
| `stores.<name>.promote.mode` | `manual` | `manual` or `auto`, as above |
| `stores.<name>.promote.include` | `['*']` | what automatic promotion copies: `settings` (application-wide values), `owners` (per-owner values), or `*` for both. The runtime file holds setting values only, so these are the only kinds |
| `stores.<name>.promote.owners` | `null` | owner values only for these morph aliases (`['user', 'team']`); `null` for every owner |
| `promote` | `null` (`LARANAIL_SETTINGS_PROMOTE`) | `manual` or `auto` for every store, overriding each store's `promote`; `null` leaves each store's own |
| `stores.<name>.mode` | `polymorphic` | `dedicated`: one owner alias, an `owner_id` foreign key to `owner.table`, owner rows only (read when migrating) |
| `stores.<name>.owner` | — | dedicated stores: `table`, `key` (`id`), `type` (`bigint`/`uuid`/`ulid`; null follows db-tools) |
| `stores.<name>.fallback` | the default store | dedicated stores: the polymorphic store that holds their global values |
| `stores.<name>.soft_deletes` | `false` | trash forgotten rows and restore them on the next write |
| `stores.<name>.approvals.keys` | `[]` | key globs (`billing.*`) whose writes become change requests |

### Tables and tenancy

| Key | Default | Purpose |
|---|---|---|
| `tables.prefix` | `''` (`LARANAIL_SETTINGS_TABLE_PREFIX`) | put in front of every package table, `[a-z0-9_]`, at most 32 characters |
| `tables.categories` | `settings_categories` | the categories table, one per connection |
| `tables.audit` | `settings_audit` | the audit table, one per connection |
| `tables.definitions` | `settings_definitions` | the definitions table |
| `readiness.flush_on` | the stancl and spatie tenancy events | events after which schema readiness is re-checked |
| `tenancy.mode` | `none` (`LARANAIL_SETTINGS_TENANCY`) | `column` adds a `tenant` column to the values, categories, audit, history and change requests tables; `database` relies on a database per tenant (read when migrating, and on every query) |
| `tenancy.discriminator` | `auto` | who the current tenant is: `auto` (stancl/tenancy, then spatie/laravel-multitenancy, when installed), `null`, `stancl`, `spatie`, or a class implementing `TenantDiscriminator` |
| `tables.history` | `settings_history` | the history table's name (after `tables.prefix`) |
| `tables.change_requests` | `settings_change_requests` | the change requests table (after `tables.prefix`), one per connection |
| `tables.stores` | `settings_stores` | the persisted configuration table, on the default store's connection |

### Layers and definitions

| Key | Default | Purpose |
|---|---|---|
| `layers.<name>.driver` | — | `database`, `json`, `php`, `config`, `array`, or a custom driver |
| `layers.<name>.connection` | `null` (`LARANAIL_SETTINGS_DB_CONNECTION` for `database`) | database driver: the connection; null is the default one |
| `layers.<name>.writable` | `true` | set `false` for a read-only layer |
| `layers.<name>.disk` | `local` (`LARANAIL_SETTINGS_RUNTIME_DISK`) | JSON driver: a private filesystem disk |
| `layers.<name>.path` | — | JSON: a path on the disk containing `{store}`; PHP: a path from the application root |
| `layers.<name>.prefix` | — | config driver: the config prefix to read |
| `definitions` | options only | `key => attributes`; see [Definitions](tools/definitions.md). `sources` and `allowed_rules` are options, not keys |
| `definitions.sources` | `code`, `config` | where definitions come from; add `database` to read rows edited at runtime (one query per store per request) |
| `definitions.allowed_rules` | common rule names | the rules a definitions-table row may use |

### Writes, cache and seeding

| Key | Default | Purpose |
|---|---|---|
| `writes.retry_on_conflict` | `true` | retry a plain `set()` once after a concurrent change |
| `seed.enabled` | `true` (`LARANAIL_SETTINGS_SEED`) | whether `db:seed` creates the default categories |
| `seed.stores` | `[]` | the stores to seed; empty means the default store |
| `seed.categories` | general, appearance, localisation, mail, notifications, security | `slug => title` or `slug => [title, description, icon, parent, sort_order, translations]` |
| `cache.enabled` | `true` (`LARANAIL_SETTINGS_CACHE`) | cache each database layer's per-scope bags |
| `cache.store` | `null` (`LARANAIL_SETTINGS_CACHE_STORE`) | a store from `config/cache.php`; null is the default store; share it between servers |
| `cache.prefix` | `laranail-settings` | the start of every cache key |
| `cache.ttl` | `3600` | seconds a cached bag is fresh (at least 1) |
| `cache.stale` | `300` | seconds after `ttl` a stale bag is still answered while it is refreshed (`Cache::flexible()`); `0` for none |

### Access

| Key | Default | Purpose |
|---|---|---|
| `authentication.resolver` | `guard` (`LARANAIL_SETTINGS_ACTOR_RESOLVER`) | how `asCurrentActor()` finds the actor: `guard`, `sanctum` or a class |
| `authentication.guards` | `[]` | guards tried in order; empty means the default guard |
| `authorization.driver` | `gate` (`LARANAIL_SETTINGS_AUTHORIZER`) | `gate`, `permissions`, a class, or a list tried in order |
| `authorization.undefined` | `deny` | decides an ability nobody defined: `deny` or `allow` |
| `authorization.require_actor` | `false` (`LARANAIL_SETTINGS_REQUIRE_ACTOR`) | refuse calls without an actor; `asSystem()` still works |
| `authorization.permissions` | `settings.<ability>` for each ability, `prune` included | permission names for the `permissions` driver; `{ability}`, `{store}`, `{key}` |
| `verification.driver` | `password_confirmation` (`LARANAIL_SETTINGS_VERIFIER`) | `password_confirmation`, `actor`, `none` or a class |
| `verification.abilities` | `override_lock`, `rollback`, `import`, `manage_stores` | abilities that always need step-up verification |
| `verification.ttl` | `900` | seconds an actor's `verifiedAt` stays fresh for the `actor` driver |

### Audit, logging and history

| Key | Default | Purpose |
|---|---|---|
| `audit.sinks` | `['auto']` | `auto` (`database` for a store with an active database layer, else `log`), `database`, `log`, `null` or classes; every sink gets every entry |
| `audit.keep_days` | `365` | how long `prune-audit` keeps audit rows |
| `logging.channel` | `null` | the application's default channel, a channel name, or `false` for off |
| `logging.level` | `info` (`LARANAIL_SETTINGS_LOG_LEVEL`) | the lowest level written |
| `history.keep_days` | `365` | how long `prune-history` keeps history entries; null for no age limit |
| `history.keep_versions` | `null` | how many versions of each setting `prune-history` keeps; null for all |

### Events and alerts

| Key | Default | Purpose |
|---|---|---|
| `listeners.alerts` | `true` | register the alert listener (alerts still need `alerts.enabled`) |
| `listeners.log` | `false` | write every settings event to the logging channel |
| `alerts.enabled` | `false` (`LARANAIL_SETTINGS_ALERTS`) | send alerts |
| `alerts.cache` | `null` | the cache store holding the alert throttle; null for the default |
| `alerts.throttle` | `300` | seconds a rule stays quiet about one key after alerting; `0` for never |
| `alerts.rules` | `[]` | which events alert whom; see [Alerts](tools/alerts.md) |

### Approvals, persisted configuration, importers and typed classes

| Key | Default | Purpose |
|---|---|---|
| `approvals.min_approvals` | `1` | approvals that apply a change |
| `approvals.allow_self` | `false` | let the requester approve their own request |
| `approvals.expire_after` | `7` | days before an open request expires; null for never |
| `approvals.keep_days` | `90` | days `prune-changes` keeps decided requests; null for ever |
| `configuration.persisted` | `false` (`LARANAIL_SETTINGS_PERSISTED_CONFIGURATION`) | read and write the stores and layers saved in `settings_stores` |
| `configuration.sources` | `['config', 'database', 'runtime']` | merge order of the file, persisted entries and runtime changes; later wins |
| `importers` | `spatie`, `toolkit`, `akaunting` | importers `import --from=` can use, name => class |
| `typed.classes` | `[]` | typed settings classes whose properties are registered as definitions |

### REST API

| Key | Default | Purpose |
|---|---|---|
| `http.enabled` | `false` (`LARANAIL_SETTINGS_HTTP`) | register the REST API's routes |
| `http.prefix` | `api/settings` (`LARANAIL_SETTINGS_HTTP_PREFIX`) | the routes' prefix; they live under `<prefix>/v1`, and an empty prefix mounts them at `/v1`. Change it when the application already has routes under `/api/settings`: Laravel keeps the last route registered for a method and URI and says nothing |
| `http.middleware` | `null` | the routes' middleware; null is `['api', 'auth:sanctum']` with Sanctum, else `['api', 'auth']` |
| `http.rate_limit` | `60` | requests per minute per user (or IP); `0` for none |
| `http.routes.<group>` | `true` | switch a group off: `stores`, `definitions`, `settings`, `history`, `audit`, `approvals`, `categories`, `configuration`, `transfer` |
| `http.idempotency.cache` / `http.idempotency.ttl` | `null` / `86400` | where and how long a write's response is kept for an `Idempotency-Key` |
| `http.owners` | unset | owner alias => model class for URLs; unset uses the morph map |
| `http.page_size` | `50` | items per page in every REST listing without `?limit=`; clamped to 1 to 200 |

Store names, keys, locales and owner aliases are validated, and a misconfiguration (an unknown driver, sink, verifier, resolver, level or ability included) raises `InvalidConfiguration` naming the config path on first use. The `password_confirmation` verifier also reads Laravel's own `auth.password_timeout`.

The table shape is fixed. The migration reads `tenancy.mode`, the table names and each store's `table`, `mode` and `owner`; change them before migrating, since the runtime reads the same keys. See [Database](tools/database.md).

Approvals are described in [Approvals](tools/approvals.md), persisted configuration in [Persisted configuration](tools/persisted-configuration.md), the REST API in [REST API](tools/rest-api.md), typed classes in [Typed settings](tools/typed-settings.md), and import and export in [Import and export](tools/import-export.md). History is described in [History](tools/history.md), events and the built-in listeners in [Events](tools/events.md), alerts in [Alerts](tools/alerts.md). The cache is described in [Caching](tools/caching.md). Access, audit and logging are described in [Access control](tools/access-control.md) and [Audit and logging](tools/audit-and-logging.md); per-request changes in [Runtime configuration](tools/runtime-configuration.md).

## Publishing

```bash
php artisan laranail::settings.install
```

The published file at `config/laranail/settings.php` is merged over the package's defaults.

---

[← Docs index](../README.md#documentation)
