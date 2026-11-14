# Commands

Nineteen Artisan commands, all named `laranail::settings.<command>`.

## The commands

| Command | Does |
|---|---|
| `laranail::settings.install` | publishes the config and the package's one migration, syncs definitions once the table exists; refuses to run over a foreign table of the same name |
| `laranail::settings.make-store {store} [--owner=*] [--connection=]` | writes a new store's migration (a copy of the package migration pinned to that store) and prints its configuration entry |
| `laranail::settings.promote [store] [--force] [--from=] [--only=] [--owner=]` | copies values written before install from the runtime file into the database; `--only=settings,owners` and `--owner=user,team` copy only some (default: the store's resolved `promote` options), and the rest stay in the file |
| `laranail::settings.doctor [--json] [--fix]` | checks each database store's readiness, waiting automatic promotion, columns, owner key type, scopes, encryption, overdue change requests and categories, then definitions and persisted configuration; `--fix` syncs definitions first |
| `laranail::settings.show {key} [--store=] [--owner=] [--locale=] [--reveal]` | prints how one setting resolves: value, layer, scope, type, version, flags and the latest audit entry |
| `laranail::settings.sync-definitions` | copies code and config definitions into the definitions table as system rows |
| `laranail::settings.prune-orphans [--dry-run]` | removes the settings of owners that no longer exist |
| `laranail::settings.prune-audit [--days=]` | removes audit rows older than `audit.keep_days` |
| `laranail::settings.history {key} [--store=] [--owner=] [--locale=] [--diff=FROM..TO] [--rollback=] [--reveal] [--limit=20]` | lists a setting's versions, compares two, or rolls it back |
| `laranail::settings.prune-history [--days=] [--versions=]` | removes history entries `history.keep_days` / `history.keep_versions` no longer keep |
| `laranail::settings.warm [--store=*]` | loads each cached store's global scope into the cache |
| `laranail::settings.clear-cache [--store=*]` | resets the cached settings of every store, or the named ones, for every tenant |
| `laranail::settings.prune-changes` | marks open change requests past `approvals.expire_after` as expired and deletes decided ones older than `approvals.keep_days` |
| `laranail::settings.export [path] [--store=] [--with-secrets] [--decrypt] [--confirm=]` | writes a store as a JSON document; secrets redacted unless asked for |
| `laranail::settings.import {source?} [--store=] [--from=] [--disk=] [--apply] [--trusted]` | imports an export file, or another package's settings through an importer, from a local path or a filesystem disk; a dry run without `--apply` |
| `laranail::settings.diff {path} [--store=]` | compares a store with an export file, by identity only; exits 1 when they differ |
| `laranail::settings.reencrypt` | encrypts every stored secret again under the current key (values, history, change requests) |
| `laranail::settings.rescope {from} {to}` | renames an owner alias in stored values, history and change requests |

Each one delegates to an action (`Actions\PromoteStore`, `Actions\PruneOrphans`, …) that the PHP
API uses too, so the console and the API cannot behave differently.

## `make-store`

```bash
php artisan laranail::settings.make-store user_settings --owner=user
php artisan migrate
```

It writes one file, `…_create_settings_user_settings_table.php`: a copy of the package migration
whose `$only` line names the store, so it creates that store's values table and nothing else.
With `--connection=archive` the copy's `$pinnedConnection` line names the connection too, and it
also creates that connection's categories and audit tables (each skipped when it already
exists); it prints the layer to add. It refuses a store that already has a migration.

## Seeding

`php artisan db:seed` runs the package's `DefaultCategoriesSeeder` (registered by the package, so
`DatabaseSeeder` does not name it); `db:seed --class` runs it alone. See
[Seed the default categories](../recipes/seeding-default-categories.md).

## `doctor`

For every store with a database layer (a store without one is reported as skipped):

| Check | Fails when |
|---|---|
| readiness | the connection does not answer or the table is missing (a warning in bootstrap mode) |
| promotion | warns when a store set to `promote: auto` still has values it selects in its runtime file although its database is ready |
| owner key | for a dedicated store, warns when `owner_id` does not have the type of the owner table's key |
| schema | a column the package uses is missing from the values, categories, audit, history or change requests table (and, on the default store's connection, the definitions and stores tables) |
| scopes | a scope names a class instead of a morph alias (fails) or an alias the store does not list (warns) |
| encryption | an encrypted value cannot be decrypted with the current or previous keys (put the old key in `APP_PREVIOUS_KEYS`, then run `reencrypt`) |
| change requests | warns when open change requests are past their expiry (schedule `prune-changes`) |
| categories | a row points at a category of another store |
| category keys | warns when a value's key does not start with its category's slug |

After the stores it checks code and config definitions the same way (`definitions: category
keys`, a warning) and lists persisted configuration entries skipped as invalid.

It exits non-zero on a failure, not on a warning, so it can gate a deploy. `--json` prints a machine-readable
report.

## `show`

```bash
php artisan laranail::settings.show mail.from_address
php artisan laranail::settings.show theme --owner=user:42 --locale=fr
```

```text
+------------+---------------------------------------------------------------------+
| Field      | Value                                                               |
+------------+---------------------------------------------------------------------+
| Key        | mail.from_address                                                   |
| Store      | settings                                                            |
| Scope      | global                                                              |
| Locale     | -                                                                   |
| Value      | "hello@example.com"                                                 |
| Layer      | database                                                            |
| From scope | global                                                              |
| Type       | string                                                              |
| Version    | 3                                                                   |
| Stored     | version 3                                                           |
| Cache      | current                                                             |
| Locked     | no                                                                  |
| Encrypted  | no                                                                  |
| Sensitive  | no                                                                  |
| Defined    | yes                                                                 |
| Last audit | update allowed by user:user:1 via rest at 2026-11-10T09:12:44+00:00 |
+------------+---------------------------------------------------------------------+
```

The resolution steps follow the table, one per line, as `explain()` returns them. The value of a
sensitive or encrypted setting prints as `[protected]` unless `--reveal` is given. `Version` and
the flags come from the row that answered; for a definition default or a miss they come from the
definition, or show `-`. `Last audit` is the newest entry for that key and scope, or `-` when the
audit trail is not stored in the database. The command runs as the system actor, through the same
`Actions\Reads\ExplainSetting` action as `explain()` and the REST API.

When a [runtime override](runtime-overrides.md) answered, `Layer` reads `override` and an
`Override` row says the value is held in memory for the request or job and not stored. Overrides
belong to the process that set them, so this shows only when the command is called from that code
(for example `Artisan::call()` in a test); a separate `php artisan` run has none.

When the answer came through the settings cache, `Stored` is the version of the row read past the
cache, and `Cache` says `stale: run laranail::settings.clear-cache` when the two differ (a row
edited by hand, say).

## `prune-orphans`

Lists, per store and alias, the owner scopes whose model no longer exists (soft-deleted models
count as existing) and removes their rows. An alias with no model in the morph map is reported and
left alone. Schedule it if you delete owners in bulk.

## `warm` and `clear-cache`

`warm` loads the global scope of every store that has a cached database layer (one query per
store on a miss, none on a hit) and prints the record count per store. `clear-cache` replaces
generation tokens: with no option every bag goes, with `--store=` only those stores'. Neither
touches stored values or the application's other cache entries, and both do nothing when
`cache.enabled` is off. Both run as the system actor and are audited as a `manage_stores` entry
(`meta.operation` `cache.warm` or `cache.clear`); their actions, `Actions\WarmCache` and
`Actions\ClearCache`, need `manage_stores` when called with an actor. See
[Caching](caching.md) and [Warm the cache after a deploy](../recipes/warming-the-cache.md).

## `history`

```bash
php artisan laranail::settings.history mail.from_address
php artisan laranail::settings.history theme --owner=user:42 --diff=3..5
php artisan laranail::settings.history mail.from_address --rollback=3
```

Without an option it lists the newest versions (operation, scope, causer, reason, time; no
values). `--diff` prints the value at two versions; an encrypted or sensitive value prints as
`[protected]` unless `--reveal` is given. `--rollback` writes the value of that version as a new
version, through the same action as `rollback()`. See [History](history.md).

## Governance commands

`export`, `import` and `diff` are described in [Import and export](import-export.md) and
[Importers](importers.md); `reencrypt` and `rescope` in [Re-encryption and rescoping](reencryption.md);
`prune-changes` in [Approvals](approvals.md). Each runs as the
system actor, is audited under its name, and delegates to the same action as the PHP API.

`doctor` also checks, per database store: encrypted values no current or previous key can read,
open change requests past their expiry, and persisted configuration entries skipped as invalid.

## Pruning and access

Both prune commands run as the system actor and are audited (a `prune` entry recording what was
removed). Their actions, `Actions\PruneOrphans` and `Actions\PruneAudit`, need the `prune`
ability when called with an actor; pruning orphans also needs `forget` for each owner it flushes.

---

[← Docs index](../../README.md#documentation)
