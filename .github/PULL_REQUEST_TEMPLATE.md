## Summary

<!-- What does this PR change, and why? -->

## Checklist

- [ ] Each new class has one clear responsibility; no speculative features or flags
- [ ] Tests cover the new behaviour and `composer test` passes deterministically
- [ ] `composer lint` is clean (Pint, PHPStan max, Rector dry run) with no new ignores
- [ ] `composer infection` meets the minimum MSI
- [ ] Errors use the package's `SettingsException` hierarchy and carry context without secrets
- [ ] No secrets, no unvalidated input, no new unaudited dependency
- [ ] `CHANGELOG.md` updated under `## [Unreleased]`
- [ ] Docs updated under `docs/` (if behaviour or public API changed)
- [ ] Commits follow [Conventional Commits](https://www.conventionalcommits.org/)
- [ ] New names are vendor-scoped (`laranail::settings.<command>`, `laranail.settings.*`, `laranail::settings-<tag>`)

## Related issues

<!-- e.g. Closes #123 -->
