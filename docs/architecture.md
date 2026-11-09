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

## Why version-checked writes?

Two requests that read a setting at version 3 and both write must not both win silently. Every
database write is an `UPDATE … WHERE version = 3`; when no row is at that version the driver
inserts, and a row at another version makes the insert hit the `(scope, key, locale)` unique
index, which the driver reports as `ConcurrentModification`. A plain upsert cannot check a
version, so it is not used. The insert runs in a savepoint, because on Postgres a failed
statement would otherwise abort the whole surrounding transaction.

## Why is the audit row in the write's transaction?

An audit trail that can miss a change is not an audit trail. With a database layer, the change
and its audit row commit together, so a failed audit insert rolls the change back. Denials and
failures are written outside any transaction, because there is nothing to roll back and the
record of the refusal must survive it.

## Why is `scope` a column, not just `settable_type` and `settable_id`?

A unique index treats `NULL`s as distinct, so global rows (no owner) could repeat. `scope`
(`global` or `alias:id`) is never null and is part of the unique key. Every lookup goes through
it, too, which keeps a string owner id from ever being compared with an integer key column on
Postgres. `settable_*` remain for reporting and for an application's own `morphTo`.

## Why actors, and why are they optional?

The package is used from controllers, jobs, commands and other packages, and it cannot know which of those calls are on a person's behalf. So the caller says: `as($user)` makes every operation, reads included, go through one `Authorizer` seam (Laravel's gate by default, so a host's existing policies and `Gate::before` rules apply), and is audited with who did it and why. Code that passes no actor is the application itself and keeps working unchanged, audited as `system`; a host that wants every call attributed turns on `authorization.require_actor`, and trusted code then has to say `asSystem()`. Undefined abilities deny, so forgetting to define a gate fails closed. A token is a ceiling on top of the gate, never a grant, so a leaked token cannot exceed its owner.

Every write is one `final` action (`Actions\SetSetting`, …) that authorizes, writes and audits. The PHP API delegates to it today; console commands and the REST API will delegate to the same actions, so the rules cannot drift between channels.

## Why are the table shapes switches, and why are they recorded?

The owner's standing rule is that where several styles are reasonable, all are supported through
configuration with the recommended one as the default. Identity columns, key types, owner
columns, soft deletes, JSON translations and the definitions table each have applications that
need the other value (uuid-keyed estates, a users table that should cascade, an admin UI that
edits definitions), so they are switches (see [Schema switches](tools/schema-switches.md)).

A switch decides a column, so it is migration-time, and the package must not trust configuration
for it afterwards: flipping `soft_deletes` on after migrating would make every read filter on a
`deleted_at` column that does not exist. So `install` and `make-store` record the shape they
produce in a schema file the application commits, the runtime and the migrations read that file,
and `doctor` reports any drift between configuration, the file and the database. A later
`make-store` keeps the file's key type, so a new table's `category_id` can never disagree with the
categories key it points at.

Every combination is a shape the suite has to keep working. A pairwise set (every pair of switch
values meets at least once, asserted along with the number of shapes) runs on every pull request
on SQLite, MySQL and PostgreSQL, and the full product weekly.

## Why does a plain set retry once, and an expected-version write never?

A plain `set()` means "make it this value"; losing a race to another writer between the read and
the write is not the caller's concern, so the write re-reads the row under a row lock (a plain
re-read under MySQL's REPEATABLE READ would see the old snapshot) and tries once more
(`writes.retry_on_conflict`). A write that names the version it expects (the REST API's
`If-Match`) is the caller asking to be told about the race, so it is never retried.

## Why is schema readiness flushed on tenancy events by name?

laranail/db-tools memoises schema readiness in a singleton, which outlives a request under Octane
and a tenant under a tenancy package: a tenant whose database is migrated would keep the previous
tenant's answer. The package forgets the memo after migrations, at the start of every queued job
and Octane request, and on each event in `readiness.flush_on`. db-tools exposes no tenant-switch
hook of its own, so the defaults name the tenancy packages' own events (stancl/tenancy's
`TenancyInitialized` / `TenancyEnded`, spatie/laravel-multitenancy's `MadeTenantCurrentEvent` /
`ForgotCurrentTenantEvent`) as strings: no dependency is added, and an event that never fires
costs nothing. Another tenancy package adds its event to the list.

## Why headless?

The package ships no views, Blade components, Livewire, Filament or assets. A settings engine is consumed by many interfaces (an admin panel, a CLI, an API client, another package), and any UI bundled here would be the wrong one for most of them and would drag view dependencies into every host. Its surfaces are the PHP API, events, Artisan commands and, later, an opt-in JSON API; an admin UI is a separate companion package. An architecture test fails on any view, Blade, Livewire or Filament reference in `src/` and on a `resources/views` directory.

## Dependencies

Built on `laranail/package-tools` (provider, testing guards), `laranail/console` (command base),
`laranail/db-tools` (schema readiness, configured key types) and `laranail/enumerator` (every
closed set — drivers, store modes, identity modes — is an enumerator-backed enum).

---

[← Docs index](../README.md#documentation)
