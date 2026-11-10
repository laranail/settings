# Configuration

Every key in `config/laranail/settings.php`, read at `config('laranail.settings.*')`.

## Keys

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
| `tables.prefix` | `''` (`LARANAIL_SETTINGS_TABLE_PREFIX`) | put in front of every package table, `[a-z0-9_]`, at most 32 characters |
| `tables.categories` | `settings_categories` | the categories table, one per connection |
| `tables.audit` | `settings_audit` | the audit table, one per connection |
| `tables.definitions` | `settings_definitions` | the optional definitions table |
| `stores.<name>.mode` | `polymorphic` | `dedicated`: one owner alias, an `owner_id` foreign key to `owner.table`, owner rows only (migration-time) |
| `stores.<name>.owner` | — | dedicated stores: `table`, `key` (`id`), `type` (`bigint`/`uuid`/`ulid`; null follows db-tools) |
| `stores.<name>.fallback` | the default store | dedicated stores: the polymorphic store that holds their global values |
| `stores.<name>.soft_deletes` | `false` | trash forgotten rows and restore them on the next write (migration-time) |
| `schema.path` | `database/settings-schema.php` (`LARANAIL_SETTINGS_SCHEMA_PATH`) | where the recorded schema shape lives |
| `identity.mode` | `slug_uid` | `slug_uid`, `slug_per_row` or `uid_only` (migration-time) |
| `ids.type` | `null` | `bigint`, `uuid` or `ulid`; null follows `laranail.db-tools.id_type` (migration-time) |
| `ids.owner_key` | `string` | `typed` types `settable_id` like the owners' keys (migration-time) |
| `translations.mode` | `lang` | `json` stores titles, labels and descriptions per locale (migration-time) |
| `categories.soft_deletes` | `false` | trash deleted categories and restore them when the slug is created again (migration-time) |
| `writes.retry_on_conflict` | `true` | retry a plain `set()` once after a concurrent change |
| `readiness.flush_on` | the stancl and spatie tenancy events | events after which schema readiness is re-checked |
| `tenancy.mode` | `none` (`LARANAIL_SETTINGS_TENANCY`) | `column` adds a `tenant` column to the values, categories and audit tables; `database` relies on a database per tenant (migration-time) |
| `tenancy.discriminator` | `auto` | who the current tenant is: `auto` (stancl/tenancy, then spatie/laravel-multitenancy, when installed), `null`, `stancl`, `spatie`, or a class implementing `TenantDiscriminator` |
| `seed.enabled` | `true` (`LARANAIL_SETTINGS_SEED`) | whether `db:seed` creates the default categories |
| `seed.stores` | `[]` | the stores to seed; empty means the default store |
| `seed.categories` | general, appearance, localisation, mail, notifications, security | `slug => title` or `slug => [title, description, icon, parent, sort_order, translations]` |
| `layers.<name>.driver` | — | `database`, `json`, `php`, `config`, `array`, or a custom driver |
| `layers.<name>.connection` | `null` (`LARANAIL_SETTINGS_DB_CONNECTION` for `database`) | database driver: the connection; null is the default one |
| `layers.<name>.writable` | `true` | set `false` for a read-only layer |
| `layers.<name>.disk` | `local` (`LARANAIL_SETTINGS_RUNTIME_DISK`) | JSON driver: a private filesystem disk |
| `layers.<name>.path` | — | JSON: a path on the disk containing `{store}`; PHP: a path from the application root |
| `layers.<name>.prefix` | — | config driver: the config prefix to read |
| `definitions` | options only | `key => attributes`; see [Definitions](tools/definitions.md). `table`, `sources` and `allowed_rules` are options, not keys |
| `definitions.table` | `false` | create the optional definitions table (migration-time) |
| `definitions.sources` | `code`, `config`, `database` | where definitions come from; `database` is read only when the table exists |
| `definitions.allowed_rules` | common rule names | the rules a definitions-table row may use |
| `authentication.resolver` | `guard` (`LARANAIL_SETTINGS_ACTOR_RESOLVER`) | how `asCurrentActor()` finds the actor: `guard`, `sanctum` or a class |
| `authentication.guards` | `[]` | guards tried in order; empty means the default guard |
| `authorization.driver` | `gate` (`LARANAIL_SETTINGS_AUTHORIZER`) | `gate`, `permissions`, a class, or a list tried in order |
| `authorization.undefined` | `deny` | decides an ability nobody defined: `deny` or `allow` |
| `authorization.require_actor` | `false` (`LARANAIL_SETTINGS_REQUIRE_ACTOR`) | refuse calls without an actor; `asSystem()` still works |
| `authorization.permissions` | `settings.<ability>` for each ability, `prune` included | permission names for the `permissions` driver; `{ability}`, `{store}`, `{key}` |
| `verification.driver` | `password_confirmation` (`LARANAIL_SETTINGS_VERIFIER`) | `password_confirmation`, `actor`, `none` or a class |
| `verification.abilities` | `override_lock`, `rollback`, `import`, `manage_stores` | abilities that always need step-up verification |
| `verification.ttl` | `900` | seconds an actor's `verifiedAt` stays fresh for the `actor` driver |
| `audit.sinks` | `['auto']` | `auto` (`database` for a store with an active database layer, else `log`), `database`, `log`, `null` or classes; every sink gets every entry |
| `audit.keep_days` | `365` | how long `prune-audit` keeps audit rows |
| `logging.channel` | `null` | the application's default channel, a channel name, or `false` for off |
| `logging.level` | `info` (`LARANAIL_SETTINGS_LOG_LEVEL`) | the lowest level written |
| `cache.enabled` | `true` (`LARANAIL_SETTINGS_CACHE`) | cache each database layer's per-scope bags |
| `cache.store` | `null` (`LARANAIL_SETTINGS_CACHE_STORE`) | a store from `config/cache.php`; null is the default store; share it between servers |
| `cache.prefix` | `laranail-settings` | the start of every cache key |
| `cache.ttl` | `3600` | seconds a cached bag is fresh (at least 1) |
| `cache.stale` | `300` | seconds after `ttl` a stale bag is still answered while it is refreshed (`Cache::flexible()`); `0` for none |
| `tables.history` | `settings_history` | the history table's name (after `tables.prefix`) |
| `history.enabled` | `true` (`LARANAIL_SETTINGS_HISTORY`) | schema switch: create the history table and record every change in it |
| `history.keep_days` | `365` | how long `prune-history` keeps history entries; null for no age limit |
| `history.keep_versions` | `null` | how many versions of each setting `prune-history` keeps; null for all |
| `stores.<name>.approvals.keys` | `[]` | key globs (`billing.*`) whose writes become change requests |
| `tables.change_requests` | `settings_change_requests` | the change requests table (after `tables.prefix`), one per connection |
| `tables.stores` | `settings_stores` | the persisted configuration table, on the default store's connection |
| `approvals.table` | `true` | schema switch: create the change requests table |
| `approvals.min_approvals` | `1` | approvals that apply a change |
| `approvals.allow_self` | `false` | let the requester approve their own request |
| `approvals.expire_after` | `7` | days before an open request expires; null for never |
| `approvals.keep_days` | `90` | days `prune-changes` keeps decided requests; null for ever |
| `configuration.persisted` | `false` (`LARANAIL_SETTINGS_PERSISTED_CONFIGURATION`) | schema switch: create `settings_stores` and read persisted stores and layers |
| `configuration.sources` | `['config', 'database', 'runtime']` | merge order of the file, persisted entries and runtime changes; later wins |
| `importers` | `spatie`, `toolkit`, `akaunting` | importers `import --from=` can use, name => class |
| `typed.classes` | `[]` | typed settings classes whose properties are registered as definitions |
| `http.enabled` | `false` (`LARANAIL_SETTINGS_HTTP`) | register the REST API's routes |
| `http.prefix` | `api/settings` (`LARANAIL_SETTINGS_HTTP_PREFIX`) | the routes' prefix; they live under `<prefix>/v1`, and an empty prefix mounts them at `/v1`. Change it when the application already has routes under `/api/settings`: Laravel keeps the last route registered for a method and URI and says nothing |
| `http.middleware` | `null` | the routes' middleware; null is `['api', 'auth:sanctum']` with Sanctum, else `['api', 'auth']` |
| `http.rate_limit` | `60` | requests per minute per user (or IP); `0` for none |
| `http.routes.<group>` | `true` | switch a group off: `stores`, `definitions`, `settings`, `history`, `audit`, `approvals`, `categories`, `configuration`, `transfer` |
| `http.idempotency.cache` / `http.idempotency.ttl` | `null` / `86400` | where and how long a write's response is kept for an `Idempotency-Key` |
| `http.owners` | unset | owner alias => model class for URLs; unset uses the morph map |
| `http.page_size` | `50` | the default page size of lists |
| `listeners.alerts` | `true` | register the alert listener (alerts still need `alerts.enabled`) |
| `listeners.log` | `false` | write every settings event to the logging channel |
| `alerts.enabled` | `false` (`LARANAIL_SETTINGS_ALERTS`) | send alerts |
| `alerts.cache` | `null` | the cache store holding the alert throttle; null for the default |
| `alerts.throttle` | `300` | seconds a rule stays quiet about one key after alerting; `0` for never |
| `alerts.rules` | `[]` | which events alert whom; see [Alerts](tools/alerts.md) |

Store names, keys, locales and owner aliases are validated, and a misconfiguration (an unknown driver, sink, verifier, resolver, level or ability included) raises `InvalidConfiguration` naming the config path on first use. The `password_confirmation` verifier also reads Laravel's own `auth.password_timeout`.

Migration-time switches (`tenancy.mode` among them) are read by `install` and `make-store` and recorded in the schema file; after that the package reads the file, and `doctor` reports configuration that differs from it. See [Schema switches](tools/schema-switches.md).

Approvals are described in [Approvals](tools/approvals.md), persisted configuration in [Persisted configuration](tools/persisted-configuration.md), the REST API in [REST API](tools/rest-api.md), typed classes in [Typed settings](tools/typed-settings.md), and import and export in [Import and export](tools/import-export.md). History is described in [History](tools/history.md), events and the built-in listeners in [Events](tools/events.md), alerts in [Alerts](tools/alerts.md). The cache is described in [Caching](tools/caching.md). Access, audit and logging are described in [Access control](tools/access-control.md) and [Audit and logging](tools/audit-and-logging.md); per-request changes in [Runtime configuration](tools/runtime-configuration.md).

## Publishing

```bash
php artisan laranail::settings.install
```

The published file at `config/laranail/settings.php` is merged over the package's defaults.

---

[← Docs index](../README.md#documentation)
