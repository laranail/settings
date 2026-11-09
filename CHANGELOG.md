# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
