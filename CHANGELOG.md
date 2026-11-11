# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Importers read from filesystem disks.** `laranail::settings.import --disk=<name>` reads an
  export file, or the `toolkit` importer's JSON file, from any configured disk, with the source as
  the path on it; local paths work as before. In PHP, the new `Contracts\DiskImporter` adds
  `readFromDisk(?string $disk, ?string $path, StoreName $store)`, which `ToolkitJsonImporter`
  implements through the injected filesystem factory. With no source, the `toolkit` importer reads
  toolkit's own file from `laranail.toolkit.settings`' disk and path when that config is loaded,
  otherwise `laranail/settings.json` on `local`; toolkit itself is not needed. The command's
  `source` argument is now optional. The REST import takes a document, so it has no disk option.

### Fixed

- **Importers no longer overwrite colliding keys in silence.** Source keys that normalise to the
  same settings key (differing by case, `-`, `_` or spaces, or toolkit's nested and dotted spelling
  of one path) made the later one win with no report. The `spatie`, `toolkit` and `akaunting`
  importers now fail with `InvalidImport` naming every colliding group
  (`[Mail-Host, mail_host] -> mail_host`) before anything is written, in a dry run and when
  applied. Input without collisions imports exactly as before. `ImportedKeys::values()` applies the
  same check for a custom importer.

## [0.1.0] - 2026-11-11

The first public release. `Added` describes the package; `Changed`, `Removed`, `Fixed` and
`Security` matter only to a checkout taken before the first tag, and [Upgrading](docs/upgrading.md)
has the steps for one.

### Added

- **Settings engine.** The `Settings` contract, facade and namespaced `settings()` helper, for any
  owner: the application, or a model by morph alias and id (`OwnerRef`, `Settable`, and
  `HasSettings` with `withSettings()` eager loading and deletion with the owner). Immutable
  `store()`, `for()`, `locale()`, `as()`, `asSystem()`, `asCurrentActor()` and `because()`
  handles; `get`, `lookup`, `has`, `missing`, `many`, `all`, typed getters, `explain`, `set`,
  `setMany`, `forget`, `forgetMany`, `remember`, `lock`, `unlock`, `flush` and `preload`. A stored
  `null` is an answer, distinct from absence. Owners can inherit through their parents, then the
  global value.
- **Layered stores** that work before the database exists and after it does: the `database`,
  `json`, `php`, `config` and `array` drivers (`DriverType`), custom drivers through
  `DriverFactory::extend()`, and `make-store` for further stores. The database layer
  version-checks every write (`ConcurrentModification`; a plain `set()` retries once under a row
  lock), and reports an outage as `DriverUnavailable` or `DriverFailure`, never as a PDO error.
- **Bootstrap and promotion.** Bootstrap mode skips the database layer only on positive evidence of
  an install in progress (`bootstrap`, `stores.<name>.bootstrap`, or laranail/installer-headless).
  `promote()` and `laranail::settings.promote` copy runtime-written values (never shipped defaults)
  into the database under a lock and archive the file. `promote: auto` does it once, as soon as the
  database is ready; `include`/`owners` (and `--only`/`--owner`) promote part of the file;
  `promoteWhenReady()` and the fluent `promotion()` setters choose it per request.
- **Definitions** in code, config, typed classes or the definitions table (`definitions.sources`):
  type (caster aliases and registered enums), default, validation rules, encryption, sensitivity,
  locks, localisation, owner restrictions, categories, step-up verification and approval.
  `laranail::settings.sync-definitions`.
- **Typed settings** (`Typed\TypedSettings` with `#[Store]`, `#[Group]`, `#[Key]`, `#[Encrypted]`,
  `#[Type]`; `typed.classes`) and settings migrations (`Typed\SettingsMigration`: `rename`,
  `move`, `add`, `delete`).
- **Categories**: create, rename, move, delete, get and list, per store, with system categories
  and `DefaultCategoriesSeeder`.
- **Access control.** `Actor` (users, tokens, services and the system), actor resolvers (`guard`,
  `sanctum` with token abilities as a ceiling), authorizers (`gate` on
  `laranail-settings.<ability>`, `permissions`, closures, chains) over 18 abilities (`Ability`),
  deny-by-default for undefined abilities, `authorization.require_actor`, guarded locks with
  `override_lock`, `view_sensitive` redaction, and step-up verification (`password_confirmation`,
  `actor`, `none`, custom).
- **Audit trail** of every write, refusal and configuration change, never holding a value, through
  the `auto`, `database`, `log` and `null` sinks; `$settings->audit()` queries it, and
  `prune-audit` trims it.
- **History** of every value change on a database layer, written in the change's transaction:
  `$settings->history()`, `rollback()`, `historyDiff()`, `laranail::settings.history` and
  `prune-history`. Encrypted payloads stay encrypted, and payloads are removed for an actor without
  `view_sensitive`.
- **Approvals.** A write to a setting that needs approval opens a change request instead:
  `propose()`, `approvals()` (`approve`, `reject`, `cancel`, `find`, listings), minimum approvals,
  no self-approval by default, expiry, and `stale` when the setting changed meanwhile;
  `prune-changes`. The proposed value is stored as the values table would store it.
- **Encryption** at rest for encrypted definitions, in values, history and change requests;
  `laranail::settings.reencrypt` after a key change.
- **Caching** of each scope's rows in front of every database layer, invalidated by generation
  tokens after commit, with stale-while-revalidate (`cache.*`), `warm` and `clear-cache`.
- **Tenancy** (`tenancy.mode`: `none`, `column`, `database`) with a `TenantDiscriminator` that
  detects stancl/tenancy and spatie/laravel-multitenancy; in `column` mode every tenant-owned row
  and query carries the tenant.
- **Export, import and diff** of a store (`export`, `import`, `previewImport`, `compare`, and the
  `export`, `import`, `diff` commands), with encrypted values redacted unless asked for, and
  importers for spatie/laravel-settings, laranail/toolkit and akaunting/laravel-setting.
- **Persisted configuration** of stores, layers and definitions in the database
  (`configuration.persisted`), validated like configuration, gated and audited.
- **REST API**, off unless `http.enabled`: 39 routes under `http.prefix`/v1, named
  `laranail-settings.api.*`, running the same actions as the PHP API, with ETags and `If-Match`,
  `202` for approvals, cursor pages, `Idempotency-Key` replays, a rate limiter and RFC 9457
  problem details.
- **Events** for every change, refusal, approval, category, promotion and prune, carrying
  identifiers only; built-in listeners for alerts (mail, Slack, database notifications, throttled)
  and logging.
- **Commands** under `laranail::settings.*`: `install`, `doctor` (readiness, missing columns,
  configuration problems; `--fix`), `show`, `history`, `promote`, `make-store`, `sync-definitions`,
  `export`, `import`, `diff`, `reencrypt`, `rescope`, `warm`, `clear-cache`, `prune-audit`,
  `prune-history`, `prune-changes` and `prune-orphans`.
- **Testing**: `Settings::fake()` with assertions for writes, forgets and audit entries; Eloquent
  models and factories for every table.
- One migration for every table, tested on SQLite, MySQL 8.4 and PostgreSQL 17 in CI.
- `values.max_bytes` (`LARANAIL_SETTINGS_MAX_VALUE_BYTES`, default 1 MiB, `null` for no limit):
  every write path (the PHP API, REST, import, promotion, approvals) refuses a value whose encoded
  payload is larger, with `InvalidSettingValue` (REST `422 invalid-value`).

### Changed

- Sanctum token abilities are vendor-scoped: `laranail-settings:<ability>` and
  `laranail-settings:*`, in place of `settings:<ability>` and `settings:*`.
- The table shape is fixed, with `tenancy.mode` as the one switch: ULID keys, a unique `uid` per
  values row, `string(36)` owner ids, JSON title maps, and the definitions, history, change
  requests and persisted configuration tables always created.
- The default store's layers are `['database', 'runtime', 'shipped']`, and `audit.sinks` defaults to
  `['auto']`.
- `definitions.sources` defaults to `['code', 'config']`; add `database` to read definitions saved
  at runtime.
- REST listings read `http.page_size` (default 50, 1 to 200) when no `?limit=` is given.
- Problem `type` URIs point at sections of the exceptions reference.
- Configuration is read with typed getters: a value of the wrong type is an error, not the default.
- Typed settings classes listed in `typed.classes` are bound at boot; one not listed resolves
  unloaded.

### Removed

Each was removed before the first tag; none was in a tagged release.

- Config keys `schema.path`, `identity.mode`, `ids.type`, `ids.owner_key`, `translations.mode`,
  `categories.soft_deletes`, `definitions.table`, `history.enabled` and `approvals.table`, with the
  `database/settings-schema.php` file, `SchemaShape`, `StoreSchema`, `SchemaShapeRepository` and the
  `IdentityMode`, `OwnerKeyType` and `TranslationsMode` enums: the shape is fixed.
- `Http\ApiRoutes`, `Enums\Capability`, the OpenAPI generator and `laranail::settings.openapi`:
  routes are declared in `routes/api.php`.
- The seven action bundles (`SettingActions`, `StoreActions`, `HistoryActions`, `ApprovalActions`,
  `TransferActions`, `ConfigurationActions`, `CategoryActions`): inject the action.
- The `CategoryRepository`, `DefinitionsRepository`, `AuditRepository`, `HistoryRepository` and
  `ChangeRequestRepository` services: the queries live on the models and in the actions.
- `Http\Middleware\FreshControllers`, `Http\Requests\BodyRequest`, `Data\Http\BodyInput`,
  `Http\Support\ListResponse`, `Support\Messages`, `Support\ConfigValues`, and the
  single-implementation contracts `ReadinessProbe`, `ValueValidator`, `ValueEncrypter`,
  `BootstrapEvidence` and `OwnerResolver`: inject the concrete class or db-tools'
  `SchemaReadinessInterface`.
- The pre-`Services\` class names and their aliases, `PruneOrphans::find()` (use `FindOrphans`) and
  `DatabaseDefinitions::localised()` (use `Support\Translations::nullableText()`).
- The four migration stubs, replaced by `create_laranail_settings_tables.php`.

### Fixed

- A malformed record in a `json` layer's file (bad key, locale, date or version) is reported as
  `DriverFailure` naming the file, instead of an `InvalidKey`, `InvalidIdentifier` or Carbon
  exception.
- `DELETE` with `If-Match` (and `ForgetSetting` with an expected version) forgets only at that
  version, re-checked under a row lock, so a change made after the check answers `412` instead of
  being deleted.
- Two approvals recorded at once both count: the request is re-read under a row lock before an
  approval is added.
- A REST `cursor` the listing did not hand out answers `422` instead of the first page.
- An `Idempotency-Key` retry sent while the first request is still running answers `409` instead
  of running the write a second time. (SP-04)
- An actor without `approve` saw an empty page of change requests when other people's newer
  requests filled the page; their own are now filtered in the query. (SP-05)
- `sync-definitions` read each definition's row and category one query at a time; it now reads
  them once per store. (SP-06)
- Every write (set, forget, lock, unlock, and so each row of an import) read all the rows of its
  scope, twice, to check one; it now reads that row only. (SP-07)
- The JSON driver lost updates under concurrent writers. A write now holds an exclusive `flock`
  on a sidecar `<file>.lock` (local disks) for its read-modify-write, and is version-checked like
  the database layer, so a stale writer gets `ConcurrentModification`. (SP-09)
- `laranail::settings.export` and `POST /stores/{store}/export` stream the document, 500 rows per
  query, instead of building the whole store in memory; the output bytes are unchanged.
  The `StreamExport` action is new; `export()` still returns the `ExportDocument`. (SP-10)
- Two overlapping `sync-definitions` runs no longer fail on each other's rows: the second waits up
  to five seconds for a cache lock, then reports `busy` and changes nothing. (SP-11)

### Security

- A change request is found only in the store that holds it. Before, `approve`, `reject` and
  `cancel` through another store's handle or route were authorized against that store and applied
  the value to it.
- Persisted configuration refuses `php` and `config` layers, saved or read back. A `php` layer
  requires the file it names, so an actor with `manage_stores` could run any PHP file on the server
  through the REST API; a `config` layer exposes application configuration.
- REST idempotency replays are keyed by the resolved actor and host as well as the method, path and
  key, so two actors the guard cannot tell apart never share a replay. A retry with a different
  body is refused with `422` instead of being answered with the first response, and the stored
  response is encrypted, since it can hold an encrypted setting's value.
- An audit query without a store filter returns entries of the stores the actor may audit only,
  and is refused (`403`) when there is none. Before, it was authorized against the default store
  and returned every store's entries.
- A persisted layer may not redefine a layer the configuration file defines, saved or read back;
  it could otherwise move a configured store onto another connection, table or driver.
- A value stored encrypted is sensitive whatever its definition now says. Redefining or deleting a
  runtime definition (`manage_definitions`) used to make the stored secret readable in plaintext
  by any actor with `view`, over REST and PHP. Reads now also check the stored row: without
  `view_sensitive`, `get()` throws `AccessDenied`, `explain()` and REST redact, listings omit it.
  `Lookup::isEncrypted()` is new. (SP-01)
- The REST API refuses a request whose actor resolver returns the system actor (`401`
  `actor-required`), which used to skip every check. (SP-02)
- An API token counts as its user in approvals: it can no longer approve its user's own change
  request, and a user and their token are one approval towards `min_approvals`. Approvals
  recorded under `token:` before this release are read as their user's. (SP-03)
- Idempotent replays are keyed by tenant as well as actor: in `column` or `database` tenancy,
  `user:1` of one tenant reusing a key used by `user:1` of another received that tenant's stored
  response. (SP-08)
