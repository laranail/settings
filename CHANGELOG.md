# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Automatic promotion. A store set to `promote: auto` (or every store, with
  `LARANAIL_SETTINGS_PROMOTE=auto`) copies its runtime file into its database layer once, the
  first time the connection answers and its table exists: straight after `migrate`, or after the
  first request or queued job that finds the database ready, once the response is sent. It runs as
  the system under the promotion lock, skips keys that already have a row, and archives the file.
  A failure is logged and retried on the next run, and reads keep coming from the file until then.
  `manual`, the default, keeps today's behaviour. The runtime configuration's
  `promote($store, $mode)` sets it per request, and `Settings::promoteWhenReady()` promotes now
  when it can and otherwise sets the store to `auto` for the rest of the request, job or command.
  `doctor` warns about an `auto` store whose file is still there while its database is ready.
- Selective promotion. `promote` also takes a long form, `['mode' => 'auto', 'include' =>
  ['settings', 'owners'], 'owners' => ['user', 'team']]`: `settings` are the application-wide
  values, `owners` the per-owner values, optionally limited to some morph aliases (`['*']`, the
  default, is everything; the runtime file holds setting values only, so these are the only
  kinds). The archive keeps the whole original file, and the values left out are written back to
  the runtime file in one rename, so they keep answering reads from there. `laranail::settings.promote`
  takes `--only=settings,owners` and `--owner=user,team`, `promoteWhenReady()` and the runtime
  configuration's `promote()` take a `PromotionSelection`, and `PromotionReport` lists what was
  `kept`.
- Fluent promotion setters: `Settings::store('settings')->promotion()->auto()->only('owners')
  ->forOwners('user')` (also `manual()`, `everything()`, `forAllOwners()`), for the current
  request or job. Each option is resolved in one place, highest first: the fluent setters, the
  runtime configuration, persisted configuration, then the configuration file. Automatic promotion,
  `promoteWhenReady()`, `doctor` and the command's defaults all read that result, and `SettingsFake`
  supports the chain.
- `laranail::settings.show` reads a cached answer's row past the cache and prints its `Stored`
  version and whether the `Cache` is stale.
- `doctor` warns when a dedicated store's `owner_id` column does not have the type of the owner
  table's key (`<store>: owner key`).

- `laranail::settings.show {key} [--store=] [--owner=] [--locale=] [--reveal]`: prints how one
  setting resolves (value, layer, scope, type, version, locked, encrypted and sensitive flags, and
  the latest audit entry), through the same `ExplainSetting` action as `explain()`. Sensitive and
  encrypted values print as `[protected]` unless `--reveal` is given.
- `doctor` warns, without failing, about a stored value or a code or config definition whose key's
  first segment is not its category's slug (`<store>: category keys`, `definitions: category keys`).
- `Logging\LogContext`: every operational log entry's context now starts with `store`, `key`,
  `scope`, `actor` and `channel`, in that order and `null` where the entry has none. Refusals,
  write retries, cache failures, alert failures, skipped persisted entries, runtime configuration
  changes and log-sink audit entries all use it, so one log query finds every entry about a setting.

- Consumer conveniences on the `Settings` contract, each a thin delegation onto the existing read
  and write paths: `missing()`, `many()` (keys or `key => default` pairs), `setMany()` and
  `forgetMany()` (one write each, not atomic), `remember()` (read-through: writes a computed value
  once through `set()`), `all(?string $prefix)` (every visible value of the handle's scope, the PHP
  form of the REST listing) and `definitions()` (the store's definitions, the PHP form of
  `GET stores/{store}/definitions`). `SettingsFake` implements all of them and records the writes.
  The facade's docblock now lists every contract method.
- A namespaced `Simtabi\Laranail\Settings\settings()` helper (`use function …\settings;`): the
  handle with no argument, else `get($key, $default)`. Deliberately not a global function.
- `Services\Resolution\ScopeKeys`: the key walk a listing uses, shared by `all()` and the REST
  listing (moved verbatim out of `Actions\Reads\ReadSettings`).
- Documentation: a complete PHP API reference (every contract method with signature, example,
  exceptions and events, plus the facade, the helper, `HasSettings` and typed settings), a per-route
  REST API reference with every problem type, an exceptions reference, the six approval events and
  a call-to-event table in the events reference, and recipes for an admin UI on the REST API, SPA and
  mobile clients, a per-user preferences screen, multi-tenancy, auditing a change, moving settings
  between environments and typed settings. Tests fail when a contract method, route, event,
  exception, command or config key (and its default) goes undocumented.

- Approvals (design §16.7, Phase 4b): a write (`set`, `forget`, `lock`, `unlock`) to a setting
  whose definition is `requiresApproval()`, or whose key matches the store's new
  `approvals.keys` globs, opens a change request instead of writing. A
  `settings_change_requests` table (one per connection, ULID keys, tenant column in column-mode
  tenancy) is created by the one migration when the new schema switch `approvals.table` is on (the
  default). The proposed value is validated and stored as the values table would store it, so an
  encrypted definition's value is encrypted at rest. `$settings->propose()` and
  `$settings->approvals()` (`Approvals\Approvals`: `pending()`, `status()`, `key()`, `for()`,
  `get()`, `find()`, `approve()`, `reject()`, `cancel()`). Approving needs `approve`; the
  requester cannot approve their own request unless `approvals.allow_self`; `approvals.min_approvals`
  approvals apply the change through the write pipeline against the base version, so a setting
  that changed meanwhile makes the request `stale`; requests expire after `approvals.expire_after`
  days. Events `ChangeRequested`, `ChangeApproved`, `ChangeApplied`, `ChangeRejected`,
  `ChangeCancelled`, `ChangeStale` (the alert listener knows the first four of interest); audit
  actions `propose`, `reject`, `cancel` with the change request's id in the `change_request`
  column; `laranail::settings.prune-changes`. Enums `ChangeStatus`, `ChangeOperation`; exceptions
  `UnknownChangeRequest`, `ChangeRequestClosed`.
- Export, import and store diff (G-10, G-42): `$settings->export(ExportMode)`, `import()`,
  `previewImport()`, `compare()`; `laranail::settings.export` (`--with-secrets`, `--decrypt` with
  the store name typed), `.import` (dry run unless `--apply`; `--trusted` for locks and system
  categories) and `.diff`. Documents name categories by slug and settings by scope, key and locale,
  never a numeric id. Imports create categories first and write each setting through the write
  pipeline, in one transaction. Gated by `export` / `import`, audited.
- Importers (G-43) behind `Contracts\Importer`: `spatie` (spatie/laravel-settings' table),
  `toolkit` (laranail/toolkit's JSON store) and `akaunting` (akaunting/laravel-setting's table),
  configured in `importers`, used with `import --from=<name>`. No dependency on any of them.
- `laranail::settings.reencrypt` (`Actions\ReencryptSettings`): encrypts live values, history and
  change requests again under the current key, leaving unreadable payloads untouched and
  reporting them. `laranail::settings.rescope {from} {to}` (`Actions\RescopeOwners`, G-30) renames
  an owner alias in values (with `settable_type` and per-row slugs), history and change requests.
- `doctor` also reports encrypted values no key can read, open change requests past their expiry,
  schema drift on the history, change requests and persisted configuration tables, and persisted
  entries skipped as invalid.
- Persisted configuration (§16.11): with the new schema switch `configuration.persisted`, a
  `settings_stores` table holds stores and layers saved through
  `$settings->persistedConfiguration()` (and the REST API), and definitions are saved to the
  definitions table. Validated like configuration, gated (`manage_stores`, `manage_definitions`),
  audited, read on first use and merged in `configuration.sources` order; an invalid entry is
  logged and skipped.
- The REST API (§16.10, Phase 4c), off unless `http.enabled`: 39 routes under `http.prefix`/v1
  named `laranail-settings.api.*`, through `http.middleware`, the `laranail-settings` rate limiter
  and an `Idempotency-Key` cache for writes. Route → FormRequest → controller → the same actions
  the PHP API runs (`Enums\Capability`), API resources, ETags with `If-Match` (412), `202` with a
  change request for approvals, cursor-paginated lists, values the actor may not view left out,
  RFC 9457 problem details, `Contracts\OwnerResolver` (morph map by default; unknown alias 404).
  New read actions `Actions\Reads\{ReadSetting, ReadSettings, ExplainSetting, ReadHistory,
  ListStores, ListDefinitions}` and `Actions\Categories\ListCategories`.
  `laranail::settings.openapi` writes an OpenAPI 3.1 document; the tests validate responses
  against it.
- Typed settings classes (Phase 5): `Typed\TypedSettings` with `load()`, `save()`,
  `register()` and the attributes `#[Store]`, `#[Group]`, `#[Key]`, `#[Encrypted]`, `#[Type]`;
  typed classes resolve from the container loaded, and `typed.classes` registers their
  definitions. Settings migrations (G-17): `Typed\SettingsMigration` with `rename()`, `move()`,
  `add()` and `delete()` (`Actions\MigrateSettings`, audited as `migrate`).
- Value history (design §4.3, Phase 4): a `settings_history` table, one per connection like the
  audit table, created by the one migration when the new schema switch `history.enabled` is on
  (the default; recorded in the schema file as `history_table`, and read as off from a file
  recorded before it existed). Every set, forget, lock, unlock and rollback on a database layer
  appends the payload before and after exactly as stored (encrypted values stay encrypted), the
  operation, the version, the causer (the actor; `system` without one), the reason and the row's
  `uid` (or id) as `setting_ref`, in the same transaction as the change, so a failed history
  insert rolls the change back. A forget writes a tombstone, and a key set again continues its
  version from the history. Column-mode tenancy adds a `tenant` column. New config
  `tables.history`, `history.enabled`, `history.keep_days` (365) and `history.keep_versions`
  (null).
- `$settings->history()` (`History\HistoryQuery`: `key()`, `for()`, `global()`, `store()`,
  `locale()`, `since()`, `until()`, `versions()`, then `get()`, `cursor()` or `paginate()`),
  gated by `view_history`; payloads of encrypted or sensitive settings are removed for an actor
  without `view_sensitive`. `$settings->rollback($key, $toVersion)` writes an earlier value as a
  new version through the normal write pipeline (gated by `rollback`, step-up verification per
  `verification.abilities`, audited as `rollback`) and returns the new version.
  `$settings->historyDiff($key, $from, $to)` returns a `Data\HistoryDiff`, decrypting only for an
  actor holding `view_sensitive` (or a trusted call). Actions `QueryHistory`, `RollbackSetting`,
  `DiffHistory`, `PruneHistory` and the bundle `HistoryActions`; `Services\Database\HistoryRepository`;
  `Data\HistoryEntry`, `HistoryFilter`, `HistoryPage`, `HistoryDiff`; `Enums\HistoryOperation`;
  `Exceptions\HistoryUnavailable`; `DatabaseDriver::reference()`; `SettingWriter::causedBy()` and
  `rollback()`.
- `laranail::settings.history {key}` (`--store`, `--owner=alias:id`, `--locale`,
  `--diff=FROM..TO`, `--rollback=`, `--reveal`, `--limit`) and `laranail::settings.prune-history`
  (`--days`, `--versions`; gated by `prune`, audited).
- Events: `SettingLocked`, `SettingUnlocked`, `SettingRolledBack`, `CategoryMoved`,
  `CategoryRenamed`, `DefinitionsSynced`, `AuditPruned`, `HistoryPruned`, `OrphansPruned` (all
  after commit), and `AccessWasDenied` and `VerificationWasRequired`, dispatched at once by the
  access guard when it refuses (the refusal throws, so its transaction rolls back). None carries
  a value.
- Built-in listeners, switchable under `listeners.*`: `Listeners\SendSettingsAlert` (on, but
  needs `alerts.enabled`) and `Listeners\LogSettingsEvent` (off; every event to the operational
  log, refusals at `warning`).
- Alerts (`alerts.enabled`, default `false`): Laravel notifications (`Notifications\SettingsAlert`)
  sent when an event matches a rule in `alerts.rules` (events, key globs, stores,
  `sensitive_only`, outcomes; `notify.mail`, `notify.slack` when
  laravel/slack-notification-channel is installed, `notify.database`; `queue`), throttled per
  rule and key for `alerts.throttle` seconds in `alerts.cache`. An alert carries identifiers only
  (`Data\AlertContext`), never a value; a test renders every channel of alerts about a seeded
  secret and fails if it appears. An alert that cannot be sent is logged, never thrown.
  `Data\AlertRule`, `Support\EventSummary`.
- `CategoryGuard::run()` takes an optional list of further events to dispatch after commit.
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
  `phpbench.json`, `composer bench`). phpbench installs into `tools/phpbench/`, outside the
  package's dependencies.

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

- REST listings read `http.page_size` (default 50, clamped to 1 to 200) for a request without
  `?limit=`; the key was documented as reserved and every listing used 50.
- Sanctum token abilities are vendor-scoped: `laranail-settings:<ability>` and
  `laranail-settings:*`, in place of `settings:<ability>` and `settings:*`. Issue tokens with the
  new names; a token holding only the old names is refused.
- Problem `type` URIs point at sections of the exceptions reference,
  `https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#<type>`
  (`#http-status` for every `http-<status>` type), instead of `…/settings/problems/<type>`,
  which had no page. Each type has an anchor there, and a test holds the two together.
- `composer test-coverage` reports coverage without a fixed `--min` floor; coverage ratchets
  instead (see CONTRIBUTING).
- With the REST API on, an `authorization.driver` that cannot be built fails closed (every actor
  denied, API requests answer `401 actor-required`, one warning logged) instead of raising
  `InvalidConfiguration` on every call. Without the API it still raises.
- The table shape is fixed, with `tenancy.mode` as the one switch: every table is keyed by a ULID,
  each values row has a unique `uid` (the history's `setting_ref`) and no `slug`, `settable_id` is
  `string(36)`, and titles, labels and descriptions are JSON maps of locale to text. The migration
  always creates the definitions, history, change requests and persisted configuration tables, and
  every values table has `deleted_at`. In `column` mode each tenant-owned table gets `tenant` in its
  unique and lookup keys. Dedicated stores keep their `owner_id` foreign key.
- History is always recorded for a store with a database layer, and change requests always have
  their table. A store on file layers only still raises `HistoryUnavailable` and
  `ApprovalUnavailable`.
- `definitions.sources` defaults to `['code', 'config']`: add `database` to read definitions saved at
  runtime, which costs one query per store per request.
- `configuration.persisted` now only turns the persisted configuration on and off; its table always
  exists.
- Deleting a category deletes the row; creating the slug again makes a new category.
- The Eloquent models are the data layer for categories, definitions, audit, history and change
  requests: `SettingsModel` holds the ULID keys, the connection and the tenant scope
  (`Models\Scopes\TenantScope`, lifted with `allTenants()`), and the new `SettingHistory` and
  `SettingChangeRequest` join `Setting`, `SettingCategory`, `SettingDefinition` and
  `SettingAuditEntry`. The database driver still reads and writes values through the query builder
  and takes the table name from `Setting::tableFor()`. `ModelContext` now holds the configuration
  and the current tenant only.
- `doctor` checks that every column the package uses exists, instead of comparing a recorded shape
  with configuration and the database.
- `QueryHistory::handle()` returns an Eloquent builder of `SettingHistory`.

- Prepared for the public release. Source, config, migration, route, helper and test comments are
  rewritten as short plain docblocks, with no references to internal planning documents; the
  design rationale they carried is now in `docs/architecture.md`. Comment lines in `src/` went
  from 3,784 to 2,743. No behaviour changed.
- The migration builds each table in one method with a flat column list; the values table's
  indexes moved to `indexValues()`. Every column, type, index name and foreign key is unchanged,
  verified by diffing the SQLite schema of the default and the full shape before and after.
- The service provider is split into one private method per concern (`registerDrivers()`,
  `registerStores()`, `registerCache()`, `registerLogging()`, `registerAudit()`,
  `registerAccess()`, `registerAlerts()`, `bootReadinessFlush()`, `bootListeners()`, …), so
  `register()` and `boot()` read as a table of contents. No binding changed.
- README: the Tests, Static analysis and MIT badges, with a note that the package is not on
  Packagist; `.gitattributes` also export-ignores `.claude`.
- The package is wired the way Laravel wires an application (conventions review, 2026-11-10).
  `routes/api.php` declares every REST route by hand in named, nested groups, each `http.routes`
  switch an `if` around its group; the service provider loads it once, under `http.prefix`/v1 and
  the `laranail-settings.api.` name prefix. URIs, names, methods and middleware are unchanged (39
  routes). `routes/web.php` is no longer loaded while it holds no routes.
- `http.prefix` reads `LARANAIL_SETTINGS_HTTP_PREFIX` (default still `api/settings`); slashes are
  trimmed and an empty prefix mounts the API at `/v1`. Change it when the application already has
  routes under `/api/settings`: Laravel keeps the last route registered for a method and URI.
- Most classes are autowired, with their lifetime as `#[Scoped]` / `#[Singleton]` on the class and
  their configuration as `#[Config]` on the parameter; the service provider binds only what
  configuration chooses or validates (1,180 lines to about 700). Closure collaborators became
  injected ones: one `Support\Clock` (Laravel's `Date` seam) for every timestamp, the operational
  log for write retries and cache failures, the event dispatcher for skipped layers, and a
  `Services\Definitions\Definitions` service for "which definition applies", which replaced a
  provider closure and two copies of the same rule.
- `SettingsManager` takes every collaborator, required, and its fluent methods return a copy; the
  "built without X" `LogicException`s are gone. `CategoryManager`, `Approvals` and
  `PersistedConfigurationEditor` take their actions and are narrowed with `in($store, $actor)`.
- REST controllers take their actions per method, return JSON resources and resource collections
  (bodies unchanged), and validate each write with its own form request.
- Each exception names its HTTP status and problem type (`STATUS`, `TYPE`); `Problems` renders them
  and reads its titles through the translator. Messages are read through Laravel's translator
  (`laranail/settings::settings.*`), `__()` and `trans_choice()` in the commands.
- Typed settings classes listed in `typed.classes` are bound when the application boots; the global
  `beforeResolving` hook is gone, so a class not listed resolves unloaded (build it with
  `::load()`).
- The REST rate limiter is registered through package-tools' `registerRateLimiter()` and reads
  `http.rate_limit` per request.
- Configuration is read with the repository's typed getters, which throw on a value of the wrong
  type where the old reader fell back to the default (a digit string for a number is now an error).

- A write to a setting that needs approval opens a change request where the store can hold one;
  `ApprovalUnavailable` is now raised only where it cannot (no active database layer, or no
  change requests table). `SetSetting`, `ForgetSetting`, `LockSetting` and `UnlockSetting` return
  the change request they opened (null when they wrote), and `WriteGuard::run()` takes an optional
  proposal and returns it.
- The `Settings` contract gains `propose()`, `approvals()`, `export()`, `import()`,
  `previewImport()`, `compare()` and `persistedConfiguration()` (pre-1.0; `SettingsManager` and
  the fake implement them).
- The schema-switch matrix covers `approvals.table`: 11 pairwise shapes for 261 pairs per pull
  request, 4,608 shapes in the weekly full product.
- The test suite runs with a 512 MB memory limit (`phpunit.xml`).
- Every service now lives under `Services\` (Phase 4 plan §1), moved verbatim:
  `Services\Database\{AuditRepository, CategoryRepository, DefinitionsRepository, SchemaInspector,
  SchemaShapeRepository}`, `Services\Stores\{SettingWriter, StoreRegistry}`,
  `Services\Resolution\{Resolver, PreloadedRecords}`, `Services\Access\{AccessGuard, Auditor}`,
  `Services\Configuration\RuntimeConfiguration`,
  `Services\Definitions\{DefinitionRegistry, DatabaseDefinitions}`,
  `Services\Tenancy\TenantDiscriminators`, `Services\Casting\CasterRegistry`,
  `Services\Values\ValuePipeline`, `Services\Drivers\DriverFactory` and
  `Services\Cache\SettingsCache`. The package's own code uses the new names. Architecture tests
  keep contract implementations out of `Services\` and every `*Repository` / `*Registry` in it.
- The `Settings` contract gains `history()`, `rollback()` and `historyDiff()`. A third-party
  implementation must add them (pre-1.0; `SettingsManager` and the fake implement them).
- With history on, a key set again after a forget continues its version (previously it started
  again at 1), so "version N" names one value.
- The access guard dispatches `AccessWasDenied` / `VerificationWasRequired` when given a
  dispatcher (the container's guard is); `PruneAudit`, `PruneOrphans` and `SyncDefinitions`
  take an optional dispatcher for their new events.
- The schema-switch matrix covers `history.enabled`: 11 pairwise shapes for 217 pairs per pull
  request, 2,304 shapes in the weekly full product.
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

### Removed

- Removed with the owner's approval (2026-11-10), before the first tag, with the fixed schema:
  the config keys `schema.path`, `identity.mode`, `ids.type`, `ids.owner_key`,
  `translations.mode`, `categories.soft_deletes`, `definitions.table`, `history.enabled` and
  `approvals.table`; `Database\SchemaShape`, `Database\StoreSchema`,
  `Services\Database\SchemaShapeRepository` and the `database/settings-schema.php` file that
  `install` and `make-store` recorded; the `Enums\IdentityMode`, `Enums\OwnerKeyType` and
  `Enums\TranslationsMode` enums; `DatabaseDriver::newId()`, `shape()` and `schemaOf()`; the
  `doctor` checks against a recorded shape; the pairwise schema matrix
  (`tests/Feature/Database/SchemaMatrixTest.php`, `tests/Support/Pairwise.php`, test groups
  `matrix`) and the weekly `matrix-full` CI job, replaced by a tenancy matrix (`none`, `column`)
  in the `database` group; and `docs/tools/schema-switches.md`, whose surviving content is in
  `docs/tools/database.md`.
- Removed with the same approval: `Services\Database\CategoryRepository`,
  `DefinitionsRepository`, `AuditRepository`, `HistoryRepository` and `ChangeRequestRepository`,
  whose queries live on the models and in the actions; and `Models\Concerns\UsesSettingsSchema`,
  replaced by `Models\Concerns\BelongsToTenant` on `SettingsModel`.

- Removed with the owner's approval (2026-11-10), before the first tag: `Http\ApiRoutes`,
  `Enums\Capability`, `Http\OpenApi\OpenApiDocument` and the `laranail::settings.openapi`
  command; `Http\Middleware\FreshControllers`; the seven action bundles (`SettingActions`,
  `StoreActions`, `HistoryActions`, `ApprovalActions`, `TransferActions`, `ConfigurationActions`,
  `CategoryActions`); `Http\Requests\BodyRequest`, `Data\Http\BodyInput` and
  `Http\Support\ListResponse`; the static `fields()` of the five resources; `Support\Messages`
  and `Support\ConfigValues`; and the single-implementation contracts `ReadinessProbe` (with
  `Database\SchemaReadinessProbe`), `ValueValidator`, `ValueEncrypter`, `BootstrapEvidence` and
  `OwnerResolver`. Inject the action, the concrete class or db-tools' `SchemaReadinessInterface`
  instead.

- The old names of the 19 classes moved into `Services\` (listed under Changed), the
  `src/deprecated-aliases.php` file that aliased them, `PruneOrphans::find()` (use
  `FindOrphans::handle()`) and `DatabaseDefinitions::localised()` (use
  `Support\Translations::nullableText()`). None was ever in a tagged release.

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
