# Changelog

All notable changes to `laranail/settings` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
