# Release

How `laranail/settings` is versioned and released.

## Versioning

Semantic versioning. Every release is a new, increasing tag (`v0.1.0`, `v0.1.1`, …). A published
tag is never moved, deleted or reused: the next fix gets the next number. Breaking changes before
1.0 bump the minor version and are listed in [Upgrading](upgrading.md).

## Cutting a release

1. On a `release/vX.Y.Z` branch, rename `## [Unreleased]` in `CHANGELOG.md` to
   `## [X.Y.Z] - YYYY-MM-DD` and open a pull request.
2. After it merges, tag the merge commit `vX.Y.Z` and push the tag.
3. The `Release` workflow publishes the GitHub release, using that version's CHANGELOG section as
   the body and attaching a CycloneDX SBOM.

## Deprecations

A deprecated API is marked `@deprecated` with the release that may remove it, keeps working, and
announces itself once with `E_USER_DEPRECATED`. Removal happens no earlier than the next minor
release before 1.0, or the next major after it.

---

[← Docs index](../README.md#documentation)
