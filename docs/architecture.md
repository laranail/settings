# Architecture

How `laranail/settings` is shaped, and why: key-value settings for any Laravel owner (the
application, a user, a tenant, a product), stored in layered stores that work even before the
database exists, and after it does.

## The model

- **Stores.** Settings live in named stores. The default store is `settings`; an application adds
  more (`user_settings`, `product_settings`) through configuration; a store added after install gets its own migration from `make-store`.
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
| Entry points | resolve inputs and delegate; no work of their own | `SettingsManager`, `CategoryManager`, `AuditQuery`, `HistoryQuery`, the console commands, the facade |
| Actions (`Actions/`) | one intent each, `handle()` only, final and readonly | `SetSetting`, `RollbackSetting`, `PromoteStore`, `SyncDefinitions`, `FindOrphans` |
| Services (`Services/`) | the database, the files, a body of rules | `Services\Database\SchemaInspector`, `Services\Stores\SettingWriter`, `Services\Resolution\Resolver`, `Services\Access\AccessGuard`, `Services\Tenancy\TenantDiscriminators`, `Services\Cache\SettingsCache` |
| Contract implementations | one per kind, beside its kind | the drivers, the audit sinks, the authorizers, the casters, the verifiers, the actor resolvers |
| Listeners (`Listeners/`) and notifications | react to events | `SendSettingsAlert`, `LogSettingsEvent`, `Notifications\SettingsAlert` |
| Models (`Models/`) | one table each: its name, casts, relations, scopes and the tenant scope | `SettingCategory`, `SettingDefinition`, `SettingAuditEntry`, `SettingHistory`, `SettingChangeRequest`, `Setting` |
| Support (`Support/`) | stateless logic shared by several classes | `AuditedTransaction`, `Translations`, `Json`, `DatabaseValues`, `ValueEncoder`, `BagCodec`, `Clock` |

Actions query categories, definitions, audit, history and change requests through the Eloquent
models and their scopes, so each table's name, casts and tenant filter live on its model. Values
are read and written by the database driver on the query builder, because that path runs on
every request. An action never touches a connection or the schema builder itself. A change and its audit entry always go
through `Support\AuditedTransaction`, so every write, category change and promotion commits and
audits the same way. Only entry points depend on actions. Architecture tests hold each rule,
and each exemption (the guards the actions run through, `QueryAudit`'s three result shapes) carries its reason and fails when it is no longer needed.

## Why a `Services` namespace?

The layering above names a service as a stateful collaborator that owns I/O or a body of rules,
but before 0.1 the services sat beside whatever they served: `SettingWriter` next to `Store` and
`Layer`, `ValuePipeline` among the stateless helpers it is not one of. Reading a directory did not
say which kind of class it held. Every service now lives under `Services\` (`Database`, `Stores`, `Resolution`, `Access`,
`Configuration`, `Definitions`, `Tenancy`, `Casting`, `Values`, `Drivers`, `Cache`),
and the classes left in their old directories are value objects, contract implementations and
entry points.

Two architecture tests keep it so: nothing under `Services\` implements a plug-in contract (a
driver, sink, authorizer, caster, verifier, resolver or discriminator stays beside its kind, where
a reader looking for "the drivers" finds them), and no class named `*Repository` or `*Registry`
lives anywhere else. The classes that moved there before 0.1 kept no old name: the package had never
been tagged, so the aliases were removed before the first release, and a third test asserts the old
names no longer resolve.

## Why history and audit are separate tables

They answer different questions. The audit trail records **actions**, every allowed change and
every refusal, with who asked, through which channel and why; it never holds a value, so it can be
read by anyone allowed `view_audit` and shipped to a log. The history records **values by
version**: what a setting held before and after each change, as stored (an encrypted value stays
encrypted), so a version can be compared or returned to. One table cannot do both: values in the
audit would put secrets in front of every auditor, and refusals in the history would put entries
that changed nothing between the versions a rollback counts.

They also keep different retention (`audit.keep_days`; `history.keep_days` and
`history.keep_versions`), and they are read with different abilities (`view_audit`;
`view_history`, plus `view_sensitive` before a protected value is decrypted). Both are written in
the change's transaction, so a failure of either rolls the change back.

## Why build rather than adopt?

No existing package combines polymorphic owners, per-store categories, multiple tables and a
file-to-database bootstrap path; the storage contracts of the leading packages
(spatie/laravel-settings, rawilk/laravel-settings, glorand/laravel-model-settings) cannot express
them. The design borrows from each and credits them here as each idea lands.

## Why a closed set of drivers?

Driver names arrive from configuration, and in multi-tenant installs possibly from database rows.
Resolving them through an enum with an exhaustive `match` (the pattern `laranail/captcha` uses)
means a name that is not a known driver resolves to nothing, rather than being interpolated into a
method call. Custom drivers are registered with closures, never class names. The same holds for a stored
`type`: it must be a registered caster alias, and the rules a definitions-table row carries are
filtered through `definitions.allowed_rules`, so no row can make the package run arbitrary code.

## Why fail closed after installation?

A short database outage on a live install must not quietly serve shipped defaults (for example a
maintenance flag) or scatter writes into each server's local file. Falling back is allowed only on
positive evidence that installation is still in progress: the operator's `bootstrap` flag, or
laranail/installer-headless reporting an install under way. Its `isInstalled()` is not used,
because it probes the database, and an outage would read as "not installed". A required layer
that cannot be reached raises `DriverUnavailable`.

The encrypter is resolved only when a value is encrypted or decrypted, so an application without
an `APP_KEY` yet still boots and reads its unencrypted settings. Decryption also tries the keys in
`APP_PREVIOUS_KEYS`.

## Why version-checked writes?

Two requests that read a setting at version 3 and both write must not both win silently. Every
database write is an `UPDATE … WHERE version = 3`; when no row is at that version the driver
inserts, and a row at another version makes the insert hit the `(scope, key, locale)` unique
index, which the driver reports as `ConcurrentModification`. A plain upsert cannot check a
version, so it is not used. The insert runs in a savepoint, because on Postgres a failed
statement would otherwise abort the whole surrounding transaction.

A write tries an update at the expected version, then the restore of a trashed row at that
version, then an insert. The one retry of a plain `set()` re-reads the row `FOR UPDATE`, because
under MySQL's REPEATABLE READ a plain re-read returns the old snapshot. A row created again after
a forget continues its version from the history, so "version N" always names one value. An
approved change request is applied against the version it was opened at, and a rollback is
written as a new version through the same pipeline, so it can itself be rolled back.

## Why is the audit row in the write's transaction?

An audit trail that can miss a change is not an audit trail. With a database layer, the change
and its audit row commit together, so a failed audit insert rolls the change back. Denials and
failures are written outside any transaction, because there is nothing to roll back and the
record of the refusal must survive it.

`AuditedTransaction` is the single commit path for every write action, category change and
promotion. On failure it rolls back, records a `failed` entry outside the transaction and
rethrows the original exception, which still wins if recording the failure fails too.

## Why is `scope` a column, not just `settable_type` and `settable_id`?

A unique index treats `NULL`s as distinct, so global rows (no owner) could repeat. `scope`
(`global` or `alias:id`) is never null and is part of the unique key. Every lookup goes through
it, too, which keeps a string owner id from ever being compared with an integer key column on
Postgres. `settable_*` remain for reporting and for an application's own `morphTo`.

## Why actors, and why are they optional?

The package is used from controllers, jobs, commands and other packages, and it cannot know which of those calls are on a person's behalf. So the caller says: `as($user)` makes every operation, reads included, go through one `Authorizer` seam (Laravel's gate by default, so a host's existing policies and `Gate::before` rules apply), and is audited with who did it and why. Code that passes no actor is the application itself and keeps working unchanged, audited as `system`; a host that wants every call attributed turns on `authorization.require_actor`, and trusted code then has to say `asSystem()`. Undefined abilities deny, so forgetting to define a gate fails closed. A token is a ceiling on top of the gate, never a grant, so a leaked token cannot exceed its owner.

Every write is one `final` action (`Actions\SetSetting`, …) that authorizes, writes and audits. The PHP API, the console commands and the REST API all delegate to these actions, so the rules cannot drift between channels.

## Why a fixed schema?

Every application gets the same tables: ULID keys, a `uid` per values row, a string owner key
(`settable_id`, 36 characters, which fits integer, UUID and ULID model keys alike), and titles,
labels and descriptions as JSON maps of locale to text. Each of those used to be a migration-time
switch, recorded in a file the runtime read back, and the suite tested the combinations. Most of the combinations served no application, and every one of them was a
branch in the migration, the driver, the models and the doctor.

A fixed shape is the shape `spatie/laravel-permission` ships: one migration that reads as what it
creates, models with one table each, and nothing to keep in step with configuration. The one
switch left is `tenancy.mode`, because a `tenant` column changes the unique keys and every query;
it is one plain `if` per table in the migration, and one global scope on the models. A dedicated
store keeps its `owner_id` foreign key to the owner table, because cascading deletes in the
database is the reason to choose one. `doctor` checks that every column the package uses exists.

## Why one migration?

Every table the package uses is created by one migration file, in dependency order (categories,
definitions, each store's values, audit, history), with a `down()` that drops them in reverse. Four
migration stubs used to do this, each repeating the same connection and shape lookups, and a new
store meant copying a stub. Nothing in the migration varies per application beyond table names
and `tenancy.mode`, so it ships as a real PHP file. Its table definitions stay inline,
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
the models' tenant scope, the audit sink and queries), so no call path can forget it, and the
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
to `team:7` stale in every user's entry that inherited from it, with no cheap way to find them.
A bag depends on one scope only: the write invalidates exactly one entry and every
inheriting user resolves the new value on the next read, in memory, from bags that are still
valid. Resolution stays a pure function of the bags, so `explain()` and the cascade behave the
same cached or not.

Invalidation replaces a random generation token instead of deleting the entry, so a
stale-while-revalidate refresh started before a write can only land under a generation no reader
uses any more, and an evicted token cannot revive an old entry the way a restarted counter
could. The key hashes connection, database, tenant, store, table and scope as a
length-prefixed tuple, so neither a shared cache store nor a `:` inside a scope can make two
tenants or databases meet. While a transaction is open on a layer's connection the
layer bypasses the cache, so nothing uncommitted is ever cached; so does a layer in bootstrap
mode, so the empty answers before installation are never cached.

A bag holds every row of its scope, not only the autoload rows, because a cached bag is treated
as complete: a partial bag would turn a non-autoload value into a miss. It is stored as plain
arrays, never objects, so the cache never unserializes a package class (applications may restrict
Laravel's `cache.serializable_classes`), and an entry written by another release, or a corrupted one, reads
as a miss, never as an empty scope.

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

The package ships no views, Blade components, Livewire, Filament or assets. A settings engine is consumed by many interfaces (an admin panel, a CLI, an API client, another package), and any UI bundled here would be the wrong one for most of them and would drag view dependencies into every host. Its surfaces are the PHP API, events, Artisan commands and an opt-in JSON API; an admin UI is a separate companion package. An architecture test fails on any view, Blade, Livewire or Filament reference in `src/` and on a `resources/views` directory.

## Why the REST API lives in the package

The API is a channel, not a product: every endpoint runs an action the PHP API already runs, with the same authorizer, verifier, audit, locks and approvals. Shipped as a separate `settings-http` package it would have to reach into those actions from outside and drift a release behind the engine whenever an action changed; it lives here so one pull request changes a capability in every channel at once. The routes are declared by hand in `routes/api.php`, each controller method takes the action it runs as a parameter, and a test reading the live router fails when a route names no controller method, a controller method is not routed, or one does not delegate to an action. It costs nothing until it is used: no route is registered, no middleware or rate limiter defined, unless `http.enabled` is on, and it adds no dependency (Sanctum stays a suggestion). It returns JSON only, so the package stays headless.

## Why change requests are a table

A write that needs approval has to outlive the request that proposed it: the approver acts later, from another request, maybe another server, and a queue or a cache entry can be evicted before anyone looks. So a proposal is a row, written in the same transaction as its `pending` audit entry, on the store's own connection (one table per connection, like the history), with the proposed value stored exactly as the values table would store it, encrypted for an encrypted definition. Keeping the base version on the row is what makes applying it safe: the approval writes through the version-checked update, so a setting that changed in the meantime is never overwritten and the request becomes `stale`. Requests are keyed by ULID, like every package table.

## Why code definitions win over the definitions table

The definitions table holds `is_system` copies of code and config definitions for admin screens,
and they are never read back: code stays the source of truth, so a synced copy from an older
release cannot override a new release's default. A row edited at runtime only answers for a key no
code definition covers.

## Why owners are stored by morph alias

Owners are referenced by alias and id (`user:7`), never by class name. Renaming a model class
orphans nothing, resolving a parent loads no model, and an alias read from the database is never
instantiated as a class. `rescope` renames an alias in values, history and change requests, and
leaves the audit trail as it was, because the trail records what happened under the old name.

## Why keys and locales are normalised

Keys are lower-cased and may not contain hyphens, which keeps the key-to-slug mapping one-to-one
(`mail.host` and `mail-host`). Locales normalise to `ll` or `ll_CC`, so two spellings never become
two rows. The identity columns are binary-collated so MySQL compares them the way the other
databases do.

## Why events carry no values

Every event carries identifiers only, so a queued listener cannot leak a secret into the jobs
table. Most events dispatch after commit. `AccessWasDenied` and `VerificationWasRequired`
dispatch at once, because the refusal usually rolls the surrounding transaction back and a
security listener must still hear of it.

## Why alerts never throw

An alert reports a change that has already committed, so an alert that cannot be sent is logged
rather than raised. When the throttle's cache fails the alert is sent anyway: a repeated alert is
better than a missed one.

## Why persisted configuration loads lazily

Persisted stores and layers are read on first use, never at boot, and an invalid row is logged and
skipped (`doctor` lists it), so a bad row cannot stop the application from booting. A persisted
entry may not re-layer a store the configuration file defines, because moving data between drivers
is `promote`'s job.

## Why promotion never copies shipped defaults

`promote` copies only values written to the runtime file before installation, so a later release
can still change a shipped default. The runtime file is archived afterwards, so a second run does
nothing. Its lock is only as wide as the cache store, so a multi-server install needs a shared
cache.

Automatic promotion (`promote: auto`) is the same action, run as the system after migrations,
after each request (from a terminating callback, so no response waits on it) and after each
queued job. Its checks are ordered by cost: the configuration first, so an application with no
`auto` store resolves nothing; then one file existence check, so a promoted store costs no query;
then the schema readiness db-tools caches. It never throws into the request: a failure is logged,
the file keeps answering reads, and the next run retries.

## Why install checks for foreign tables

A bare `settings` table plausibly belongs to the application. Installing over it would fail half
way or write into it, so `install` recognises the package's tables by columns only they have and
refuses to run over anything else. An unreachable database is not reported as foreign.

## Why `settings()` is namespaced

A global `settings()` function is a name many packages and applications already define, and a
second definition either fatals or silently wins. The helper lives in the package's namespace.

## Why the models get a static context

Eloquent builds models with `new static`, so nothing can be injected into them. The provider gives
`ModelContext` closures that resolve the scoped schema, tenant and default store on each call, so
no request or tenant state is held statically. The engine never reads through the models; they
exist for factories, seeders, admin screens and tests.

## How the container builds it

Most classes are autowired. A class's lifetime is an attribute on the class (`#[Scoped]` for
anything derived from a request, so Octane and queue workers start each request clean;
`#[Singleton]` for stateless helpers), and a configuration value is a `#[Config]` attribute on the
constructor parameter that needs it. The service provider binds only what configuration chooses or
validates: the drivers, sinks, authorizers, verifiers and actor resolvers named in config, the cache
stores, and the stores themselves. A handle (`SettingsManager`) takes every collaborator it calls,
required, and a fluent method returns a copy with a new target, so a handle is never half-built.
Time is read in one place, `Support\Clock`, through Laravel's `Date` seam, so `travelTo()` reaches
every timestamp the package writes.

A few collaborators stay closures on purpose, each because the alternative is worse: the
`DriverFactory` is a singleton (a driver registered with `extend()` must last) and hands database
drivers the scoped readiness, schema and tenant services through closures; the runtime
configuration's change log and the database audit sink reach the logger, the auditor and the store
registry lazily, because those read the runtime configuration themselves; the encrypter is resolved
only when a value is encrypted, so an application without an `APP_KEY` still boots; and the session
and request are read per call, because they change under a scoped service.

## Dependencies

Built on `laranail/package-tools` (provider, testing guards), `laranail/console` (command base),
`laranail/db-tools` (schema readiness, configured key types) and `laranail/enumerator` (every
closed set — drivers, store modes, tenancy modes — is an enumerator-backed enum).

---

[← Docs index](../README.md#documentation)
