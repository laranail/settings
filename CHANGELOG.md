# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The settings cache (design phase 3, §7.5): `Drivers\CachingDriver` puts each database layer
  behind `Caching\SettingsCache`, which caches every scope's raw rows (a "bag", every locale,
  misses as empty bags) keyed by a hashed, length-prefixed tuple of connection, database, tenant,
  store, table and scope. Invalidation replaces ULID generation tokens (per scope, per store, and
  a global epoch) on every write path: set, forget, lock, unlock, flush, owner deletion and
  promotion, after the write's transaction commits. Past `cache.ttl` a bag is still answered for
  `cache.stale` seconds through `Cache::flexible()` while it is refreshed; a refresh can never
  restore an invalidated value. Reads inside an open transaction and while bootstrap mode skips
  the layer go straight to the database. A failing cache store falls back to the database and is
  logged. New keys `cache.enabled` (default `true`), `cache.store`, `cache.prefix`, `cache.ttl`
  and `cache.stale`.
- `laranail::settings.warm` and `laranail::settings.clear-cache` (`--store=*`), and
  `clearCache()` / `warmCache()` on a settings handle, through the new `Actions\WarmCache` and
  `Actions\ClearCache`: gated by `manage_stores` when an actor is present and audited as a
  `manage_stores` entry (`meta.operation` `cache.warm` / `cache.clear`).
- `Store::isCached()`, `forgetCached()`, `forgetCachedStore()` and `warmCache()`;
  `Data\CacheTarget`; `Support\BagCodec`.
- A phpbench benchmark of one request's reads with and without the cache (`benchmarks/`,
  `phpbench.json`, `composer bench`).

- Tenancy (`tenancy.mode`: `none`, `column`, `database`; recorded in the schema file). Column
  mode adds a `tenant` column to the values, categories and audit tables, leading their unique
  keys; every row is stamped with the current tenant and every query, the audit trail and the
  models included, is limited to it. A `TenantDiscriminator` contract (`NullTenantDiscriminator`,
  `ClosureTenantDiscriminator`) picked by `tenancy.discriminator` (`auto` detects stancl/tenancy,
  then spatie/laravel-multitenancy; both only suggested). Preloaded records are kept per tenant
  in every mode. New enum `TenancyMode`.
- Default categories: `DefaultCategoriesSeeder` (in `database/seeders`, registered with the
  application's `db:seed`) creates `seed.categories` (general, appearance, localisation, mail,
  notifications, security) as system categories in `seed.stores`, through the settings API as
  the system actor; idempotent; `seed.enabled` switches it off. `NewCategory` takes `system`.
- Eloquent models (`Models\Setting`, `SettingCategory`, `SettingDefinition`,
  `SettingAuditEntry`) and their factories in `database/factories`, for tests, seeding and admin
  screens; they follow the recorded shape and tenancy. The engine itself stays on the query
  builder.
- Schema switches (design phase 2b, §4.0): `identity.mode` (`slug_uid`, `slug_per_row`,
  `uid_only`), `ids.type` (`bigint`, `uuid`, `ulid`; follows `laranail.db-tools.id_type` unless
  set) with matching category keys, `ids.owner_key` (`string`, `typed`), `stores.<name>.mode`
  (`polymorphic`, `dedicated` with an `owner_id` foreign key, `owner.*` and a polymorphic
  `fallback` store for global values), `stores.<name>.soft_deletes` (forget trashes; the next
  write restores through the version check, never as a conflict) and `categories.soft_deletes`,
  `translations.mode` (`lang`, `json`; `NewCategory` `translations`, `Category::$titles` and
  `titleIn()`), and `tables.definitions`. New enums `IdentityMode`, `IdType`, `OwnerKeyType`,
  `StoreMode`, `TranslationsMode`.
- The schema-shape file (`database/settings-schema.php`, `schema.path`): `install` records the
  shape its migrations produce and `make-store` adds its store; the migrations and the runtime
  read the record, not live configuration (`SchemaShape`, `StoreSchema`,
  `SchemaShapeRepository`).
- `doctor` drift detection: configuration against the schema file, the file's shape version
  against this release, unrecorded database stores, and the live tables against the recorded
  shape (missing or unexpected columns, wrong `id` type). `doctor --fix` syncs definitions.
- The optional `settings_definitions` table (D-6, `definitions.table`) with its migration,
  `laranail::settings.sync-definitions` (code and config definitions as `is_system` rows; stale
  ones removed; runtime rows never touched; gated by `manage_definitions`, audited), and the
  `database` definition source for keys no code covers, with rules filtered through
  `definitions.allowed_rules`. `definitions.sources` selects `code`, `config` and `database`.
- `$settings->audit()`: an immutable audit query (`for()`, `global()`, `store()`, `key()`,
  `action()`, `outcome()`, `since()`, `until()`, then `get()`, `cursor()` or `paginate()` returning
  an `AuditPage`), gated by `view_audit`; `Actions\QueryAudit`, `Data\AuditFilter`.
- A plain `set()` retries once after a concurrent change, re-reading the row under a row lock
  (`writes.retry_on_conflict`, default on; logged). `SettingWrite::$expectedVersion` makes a
  write conditional on a version and is never retried (`SettingWriter::setExpecting()`).
- The `prune` ability (and audit action): `Actions\PruneOrphans` and `Actions\PruneAudit` need it
  when called with an actor, and both prunes are audited.
- `readiness.flush_on`: schema readiness is forgotten on tenancy events (stancl/tenancy and
  spatie/laravel-multitenancy by default), since db-tools has no tenant hook of its own.
- The §13 switch matrix: a pairwise set of 10 shapes covering all 141 value pairs (both
  asserted) on SQLite, MySQL and PostgreSQL, and the full 576-shape product weekly
  (`LARANAIL_SETTINGS_MATRIX=full`, CI job `matrix-full`).

### Changed

- The code is layered entry points → actions → services → support, held by architecture
  tests. Database work moved out of actions into `Database\AuditRepository` (the audit sink,
  query and prune), `Database\DefinitionsRepository` (definitions sync and reads) and
  `Database\SchemaInspector` (`doctor` and install's foreign-table check); the tenant adapters
  into `Tenancy\TenantDiscriminators`; the transaction-and-audit sequence of every write,
  category change and promotion into `Support\AuditedTransaction`; shared helpers into
  `Support\Json`, `Support\Translations`, `Support\DatabaseValues` and
  `Support\ConfigValues`, and `Scope::of()`, `Scope::fromString()`, `Scope::owner()`,
  `Store::requireDatabase()`, `CategoryRepository::of()`. Behaviour, messages and audit entries
  are unchanged.
- `FindOrphans` finds orphaned owners' settings; `PruneOrphans` removes them.
- With caching on (the default), a store's database layer is a `CachingDriver` in front of the
  `DatabaseDriver`; `->database()` still returns the `DatabaseDriver`. Rows changed past the API
  (raw queries, `Models\Setting` saves) are not seen until `clear-cache` runs or the bag expires.
- `Contracts\Settings` gains `clearCache()` and `warmCache()`; `Actions\StoreActions` gains the
  optional `warmCache` and `clearCache` actions.

### Deprecated

- `PruneOrphans::find()`: use `FindOrphans::handle()`. It forwards; removed in 1.0.
- `DatabaseDefinitions::localised()`: use `Support\Translations::nullableText()`. It forwards;
  removed in 1.0.

- The four migration stubs are replaced by one migration,
  `database/migrations/create_laranail_settings_tables.php`, which creates categories, the
  optional definitions table, every recorded database store's values table (on its own
  connection, with that connection's categories and audit tables) and the audit table, in
  that order, and drops them in reverse. `install` publishes it; migrations published from the
  stubs keep working. `make-store` writes one migration per store, a copy of it pinned to the
  store (and connection), instead of up to three stub copies.
- The schema-switch matrix covers `tenancy.mode`: 10 pairwise shapes for 177 pairs per pull
  request, 1,152 shapes in the weekly full product.
- The migration stubs read the recorded schema shape (or configuration, when nothing is
  recorded) instead of individual config keys, and run on the store's recorded connection.
- `laranail::settings.install` publishes a fourth migration (`create_settings_definitions_table`,
  a no-op while the table is off), records the schema file and syncs definitions.
- `laranail::settings.prune-audit` now writes an audit entry for the prune itself.
- The `Settings` contract gains `audit()`. A third-party implementation of the contract must add
  it (pre-1.0; `SettingsManager` and the fake implement it).

- The database layer (design phase 2, default schema): the `database` driver
  (`DriverType::Database`) over the query builder, with version-checked writes that raise
  `ConcurrentModification`, inserts in a savepoint, and database errors surfaced as
  `DriverUnavailable` (unreachable or table missing) or `DriverFailure`, never as PDO exceptions.
- Publishable migrations for `settings_categories`, `settings` (polymorphic, `slug_uid`,
  bigint ids, string owner key, binary-collated identity) and `settings_audit`;
  `tables.prefix`, `tables.categories`, `tables.audit` and `stores.<name>.table`.
- `laranail::settings.install` now also publishes the migrations, and refuses to run over a
  table of the same name that is not the package's (D-4).
- Bootstrap mode (`stores.<name>.bootstrap`, `bootstrap` / `LARANAIL_SETTINGS_BOOTSTRAP`, or
  laranail/installer-headless' `isInstalling()`): the database layer is skipped only on positive
  evidence of an install in progress and while the database is not ready, with a `LayerSkipped`
  event. Readiness comes from laranail/db-tools and is flushed after migrations and per job.
- Promotion: `$settings->promote()` and `laranail::settings.promote` copy runtime-written values
  (never shipped defaults) into the database, skip existing rows unless forced, replay an
  archive with `--from`, take a cache lock, and archive the runtime file. `StorePromoting` /
  `StorePromoted` events; `PromotionLocked`.
- Categories: `$settings->categories()` with create, rename, move, delete, get and all, gated by
  `manage_categories` and audited; `CategoryCycle`, `CategoryStoreMismatch`, `UnknownCategory`;
  `CategorySaving`/`Saved`/`Deleting`/`Deleted` events. Definitions' categories are linked per
  store (G-33).
- `HasSettings` for Eloquent owners: `settings()`, `withSettings()` eager loading, and deletion
  of an owner's settings with the owner (on force delete for soft-deleting models).
- `$settings->preload()` (one read per store for a list of owners) and `$settings->flush()` (an
  owner's values, gated by `forget`), with `ScopeFlushing` / `ScopeFlushed` events.
- The `database` and `auto` audit sinks; an allowed change and its audit row commit in one
  transaction, so a failed audit insert rolls the change back. `audit.keep_days` and
  `laranail::settings.prune-audit`.
- `laranail::settings.make-store`, `laranail::settings.doctor` (readiness, schema drift,
  class-name and unlisted scopes, cross-store categories) and
  `laranail::settings.prune-orphans`.
- CI runs the `database` test group on MySQL 8.4 and PostgreSQL 17.

- Access control (design phase 1b): the `Actor` authentication object (`Actor::user()`,
  `Actor::system()`, never serialising the model) and `as()`, `asSystem()`, `asCurrentActor()`,
  `because()` and `actor()` on the `Settings` contract. A handle with an actor authorizes every
  operation, reads included; a handle without one runs as trusted code, audited as `system`.
  `authorization.require_actor` refuses actorless calls (`ActorRequired`).
- Actor resolvers behind `Contracts\ActorResolver`: `guard` (default), `sanctum` (token
  abilities become a ceiling; `laravel/sanctum` suggested only) and custom classes.
- The enumerator-backed `Ability` enum (17 abilities), `Subject` and `Decision`, and
  authorizers behind `Contracts\Authorizer`: `gate` (`laranail-settings.<ability>`, default),
  `permissions` (`$user->can()`), closures and chains. Undefined abilities deny unless
  `authorization.undefined` is `allow`. Denials raise `AccessDenied`, carrying no value.
- Guarded locks: `lock` and `unlock` abilities, and `override_lock` on stores with
  `locks.overridable`. Sensitive definitions (`sensitive()`, implied by `encrypted()`) need
  `view_sensitive`, and `explain()` redacts what the actor may not see.
- Step-up verification behind `Contracts\Verifier`: `password_confirmation` (default),
  `actor`, `none` and custom classes; `requiresVerification()` definitions and
  `verification.abilities`; `VerificationRequired`.
- `requiresApproval()` definitions, which raise `ApprovalUnavailable` until approvals ship
  with the database layer.
- An audit trail of writes, forgets, locks, unlocks, refusals and configuration changes
  (`AuditEntry`, `AuditAction`, `AuditOutcome`) that never records a value, through
  `Contracts\AuditSink` sinks `log` (default), `null` and custom classes.
- An operational log on `logging.channel` (or off with `false`) at `logging.level`.
- `RuntimeConfiguration` through `configuration()` and `withConfiguration()`: stores, layers,
  drivers, definitions, authorizer, verifier, resolver, audit sinks, log and the actor
  requirement for one request, validated like config and bound `scoped`.
- The actions layer: `SetSetting`, `ForgetSetting`, `LockSetting` and `UnlockSetting`, each
  taking a DTO (`SettingWrite`, `SettingTarget`) and the actor; the manager delegates to them.
- `Settings::fake()` records audit entries and gains `assertAudited()`, `assertDenied()` and
  `auditEntries()`.
- Guards: the package is headless (no view, Blade, Livewire or Filament reference, no
  `resources/views`), and write actions are final with a `handle()` method.

### Changed

- The default store's layers are now `['database', 'runtime', 'shipped']`: run the migrations
  after install, or drop `database` from the layers to stay on files.
- `audit.sinks` defaults to `['auto']` (the database for a store with a database layer, the log
  otherwise) instead of `['log']`.
- `Record` gains optional `group`, `category` and `public` fields; `SettingWriter::lock()` and
  `unlock()` take an optional definition; `Store`, `StoreRegistry`, `DriverFactory` and
  `SettingsManager` take further trailing optional constructor arguments. Existing calls are
  unaffected.
- The `Settings` contract gains `flush()`, `preload()`, `categories()` and `promote()`.

- `SettingWriter::set()`, `lock()` and `unlock()` return the stored `Record`, and `forget()`
  the removed one (or null), instead of nothing. Callers that ignored the result are unaffected.
- `SettingsManager` takes its actions, access guard, runtime configuration, actor and reason
  as trailing optional constructor arguments. A manager built without them behaves as before
  for trusted calls and denies every actor.

- The settings engine (design phase 1): the `Settings` contract and facade with immutable
  `store()`, `for()` and `locale()` handles; `get`, `lookup`, `has`, typed getters, `explain`,
  `set`, `forget`, `lock` and `unlock`.
- Layered stores with the `json`, `php`, `config` and `array` drivers, resolved through the
  enumerator-backed `DriverType` enum; custom drivers through `DriverFactory::extend()` closures.
- Owners by morph alias and id (`OwnerRef`, `Settable`), store routing by alias, and opt-in
  inheritance through parents in their own stores, then the global value.
- Definitions in code or config: types (caster aliases, including registered enums), defaults,
  validation of the raw value, encryption, locks, localisation, owner restrictions and
  precedence.
- Presence-aware reads: a stored `null` is an answer, distinct from absence.
- `SettingSaving`, `SettingSaved`, `SettingForgetting` and `SettingForgotten` events, dispatched
  after commit and carrying identifiers only.
- `Settings::fake()` with `assertSaved`, `assertNotSaved` and `assertForgotten`.
- An exception hierarchy under `SettingsException`.
- Package skeleton on `laranail/package-tools`, with `laranail/console`, `laranail/db-tools` and
  `laranail/enumerator` as dependencies.
- `SettingsServiceProvider`, the `laranail.settings` config key and the
  `laranail::settings.install` command, which publishes the config under
  `laranail::settings-config`.
- Guards: architecture rules, vendor-scoped names read from the live registries, config read
  only at the registered key, provider layout, no literal test credentials, and the docs
  standard.
- CI: tests, static analysis, Pest mutation testing (minimum score 70), security audit,
  release hygiene and a tag-driven release.
