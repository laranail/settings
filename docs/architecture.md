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

## How the code is layered

| Layer | Holds | Examples |
|---|---|---|
| Entry points | resolve inputs and delegate; no work of their own | `SettingsManager`, `CategoryManager`, `AuditQuery`, the console commands, the facade |
| Actions (`Actions/`) | one intent each, `handle()` only, final and readonly | `SetSetting`, `PromoteStore`, `SyncDefinitions`, `FindOrphans` |
| Services | the database, the files, a body of rules | `Database\AuditRepository`, `Database\DefinitionsRepository`, `Database\SchemaInspector`, `Database\CategoryRepository`, the drivers, `Stores\SettingWriter`, `Resolution\Resolver`, `Authorization\AccessGuard`, `Tenancy\TenantDiscriminators`, `Caching\SettingsCache` |
| Support (`Support/`) | stateless logic shared by several classes | `AuditedTransaction`, `Translations`, `Json`, `DatabaseValues`, `ConfigValues`, `ValueEncoder`, `BagCodec` |

An action never builds a query or reads the schema itself; it asks a repository, so the table,
the tenant filter and the row format live in one place. A change and its audit entry always go
through `Support\AuditedTransaction`, so every write, category change and promotion commits and
audits the same way. Only entry points depend on actions. Architecture tests hold each rule,
and each exemption (the guards the actions run through, the action bundles, `QueryAudit`'s three
result shapes) carries its reason and fails when it is no longer needed.

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
`deleted_at` column that does not exist. So `install` and `make-store` record the shape the
migration produces in a schema file the application commits, the runtime and the migration read that file,
and `doctor` reports any drift between configuration, the file and the database. A later
`make-store` keeps the file's key type, so a new table's `category_id` can never disagree with the
categories key it points at.

Every combination is a shape the suite has to keep working. A pairwise set (every pair of switch
values meets at least once, asserted along with the number of shapes) runs on every pull request
on SQLite, MySQL and PostgreSQL, and the full product weekly.

## Why one migration?

Every table the package uses is created by one migration file, in dependency order (categories,
definitions, each store's values, audit), with a `down()` that drops them in reverse. Four
migration stubs used to do this, each repeating the same connection and shape lookups, and a new
store meant copying a stub. Nothing in the migration varies per application any more (the shape
comes from the schema file), so it ships as a real PHP file. Its table definitions stay inline,
in short private methods, rather than in a schema-builder class: a migration is read when it
fails, and one file that says what it creates is easier to maintain than a migration delegating
to code elsewhere. `make-store` copies that same file and pins one store (and, with
`--connection`, a connection), so there is still exactly one definition of each table.

## Why tenancy this way?

Settings are a tenant's own data, so tenancy is in the core rather than a companion package, and
it needs neither tenancy package: a `TenantDiscriminator` says who the current tenant is, with
adapters for stancl/tenancy and spatie/laravel-multitenancy picked by `class_exists`. A tenant
column (one database) and a database per tenant are both common, so both are modes, and the
default is none. In column mode the filter sits where every query is built (the database driver,
the categories repository, the audit sink and queries), so no call path can forget it, and the
tenant leads the unique keys so two tenants can hold the same key. Definitions describe settings
rather than hold values, so they stay shared. Memoised reads are keyed by tenant in every mode,
because a job that loops over tenants is the easiest way to leak one tenant's settings into
another's.

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

## Why cache raw bags?

The cache holds each scope's raw rows (a "bag"), never resolved values. A resolved value depends
on every scope the cascade walked (owner, team, tenant, global), so caching it would make a write
to `team:7` stale in every user's entry that inherited from it, with no cheap way to find them
(G-32). A bag depends on one scope only: the write invalidates exactly one entry and every
inheriting user resolves the new value on the next read, in memory, from bags that are still
valid. Resolution stays a pure function of the bags, so `explain()` and the cascade behave the
same cached or not.

Invalidation replaces a random generation token instead of deleting the entry, so a
stale-while-revalidate refresh started before a write can only land under a generation no reader
uses any more (R-23), and an evicted token cannot revive an old entry the way a restarted counter
could (R-41). The key hashes connection, database, tenant, store, table and scope as a
length-prefixed tuple, so neither a shared cache store nor a `:` inside a scope can make two
tenants or databases meet (G-37, R-63). While a transaction is open on a layer's connection the
layer bypasses the cache, so nothing uncommitted is ever cached.

Measured with `composer bench` (phpbench 1.7, PHP 8.5.7, SQLite file database, 200 revolutions x
5 iterations per subject; one revolution is one request reading ten settings through
owner → team → global):

| Subject | Mode time per request | vs. uncached |
|---|---|---|
| uncached (`cache.enabled` off) | 3.81 ms, 10 queries | — |
| cold (every bag invalidated first) | 2.22 ms, 1 query | 1.7x faster |
| warm, `array` store | 2.08 ms, 0 queries | 1.8x faster |
| warm, `file` store | 2.48 ms, 0 queries | 1.5x faster |

Measured 2026-11-10 on a developer Mac. About 2 ms of every request is rebuilding the scoped
services, which all four pay alike. Without the cache each `get()` loads its scopes again (ten
queries for ten reads); with it, one request costs one query cold and none warm, so the gain is
the queries saved. On a local SQLite file a query is cheap, so against a database across a network
each saved query is a round trip and the gain is larger. A shared cache store (Redis) costs two
round trips per store on the first read of a request and none after it.

## Why headless?

The package ships no views, Blade components, Livewire, Filament or assets. A settings engine is consumed by many interfaces (an admin panel, a CLI, an API client, another package), and any UI bundled here would be the wrong one for most of them and would drag view dependencies into every host. Its surfaces are the PHP API, events, Artisan commands and, later, an opt-in JSON API; an admin UI is a separate companion package. An architecture test fails on any view, Blade, Livewire or Filament reference in `src/` and on a `resources/views` directory.

## Dependencies

Built on `laranail/package-tools` (provider, testing guards), `laranail/console` (command base),
`laranail/db-tools` (schema readiness, configured key types) and `laranail/enumerator` (every
closed set — drivers, store modes, identity modes — is an enumerator-backed enum).

---

[← Docs index](../README.md#documentation)
