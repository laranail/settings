# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
