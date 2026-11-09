# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
