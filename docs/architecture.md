# Architecture

How `laranail/settings` is shaped, and why.

## The model

- **Stores.** Settings live in named stores. The default store is `settings`; an application adds
  more (`user_settings`, `product_settings`) through configuration and a generated migration.
- **Layers.** A store reads through ordered layers, each backed by a driver: the database, a
  runtime JSON file, a shipped PHP file, config. Before the application is installed, a store
  that opts in can serve from its files; once installed, an unreachable database is an error, never
  a silent fallback.
- **Owners.** A setting belongs to the application or to a model (`settable_type` /
  `settable_id`, Laravel's `*able` naming). Owners can inherit from parents (user → team →
  tenant) when a setting's definition allows it.
- **Categories.** A shared `settings_categories` table, scoped per store, organises settings for
  people and interfaces; `group` remains a lightweight code namespace.

## Why build rather than adopt?

No existing package combines polymorphic owners, per-store categories, multiple tables and a
file-to-database bootstrap path; the storage contracts of the leading packages
(spatie/laravel-settings, rawilk/laravel-settings, glorand/laravel-model-settings) cannot express
them. The design borrows from each and credits them here as each idea lands.

## Why a closed set of drivers?

Driver names arrive from configuration, and in multi-tenant installs possibly from database rows.
Resolving them through an enum with an exhaustive `match` (the pattern `laranail/captcha` uses)
means a name that is not a known driver resolves to nothing, rather than being interpolated into a
method call. Custom drivers are registered with closures, never class names.

## Why fail closed after installation?

A short database outage on a live install must not quietly serve shipped defaults (for example a
maintenance flag) or scatter writes into each server's local file. Falling back is allowed only on
positive evidence that installation is still in progress.

## Dependencies

Built on `laranail/package-tools` (provider, testing guards), `laranail/console` (command base),
`laranail/db-tools` (schema readiness, configured key types) and `laranail/enumerator` (every
closed set — drivers, store modes, identity modes — is an enumerator-backed enum).

---

[← Docs index](../README.md#documentation)
