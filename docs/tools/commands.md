# Commands

Nineteen Artisan commands, all named `laranail::settings.<command>`.

## The commands

| Command | Does |
|---|---|
| `laranail::settings.install` | publishes the config and the package's one migration, records the schema file, syncs definitions once the table exists; refuses to run over a foreign table of the same name |
| `laranail::settings.make-store {store} [--owner=*] [--connection=]` | writes a new store's migration (a copy of the package migration pinned to that store), records it in the schema file and prints its configuration entry |
| `laranail::settings.promote [store] [--force] [--from=]` | copies values written before install from the runtime file into the database |
| `laranail::settings.doctor [--json] [--fix]` | checks readiness, schema drift against the schema file and rows the database cannot guard; `--fix` syncs definitions first |
| `laranail::settings.sync-definitions` | copies code and config definitions into the definitions table as system rows |
| `laranail::settings.prune-orphans [--dry-run]` | removes the settings of owners that no longer exist |
| `laranail::settings.prune-audit [--days=]` | removes audit rows older than `audit.keep_days` |
| `laranail::settings.history {key} [--store=] [--owner=] [--locale=] [--diff=FROM..TO] [--rollback=] [--reveal] [--limit=20]` | lists a setting's versions, compares two, or rolls it back |
| `laranail::settings.prune-history [--days=] [--versions=]` | removes history entries `history.keep_days` / `history.keep_versions` no longer keep |
| `laranail::settings.warm [--store=*]` | loads each cached store's global scope into the cache |
| `laranail::settings.clear-cache [--store=*]` | resets the cached settings of every store, or the named ones, for every tenant |
| `laranail::settings.prune-changes` | marks open change requests past `approvals.expire_after` as expired and deletes decided ones older than `approvals.keep_days` |
| `laranail::settings.export [path] [--store=] [--with-secrets] [--decrypt] [--confirm=]` | writes a store as a JSON document; secrets redacted unless asked for |
| `laranail::settings.import {source} [--store=] [--from=] [--apply] [--trusted]` | imports an export file, or another package's settings through an importer; a dry run without `--apply` |
| `laranail::settings.diff {path} [--store=]` | compares a store with an export file, by identity only; exits 1 when they differ |
| `laranail::settings.reencrypt` | encrypts every stored secret again under the current key (values, history, change requests) |
| `laranail::settings.rescope {from} {to}` | renames an owner alias in stored values, history and change requests |
| `laranail::settings.openapi [--output=]` | writes the OpenAPI 3.1 document of the REST API (needs `http.enabled`) |

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

For every store with a database layer:

| Check | Fails when |
|---|---|
| schema file | the file is older than this release's shape version, or a database store has no entry (warns when there is no file, or when configuration differs from it) |
| readiness | the connection does not answer or the table is missing (a warning in bootstrap mode) |
| schema | a column the recorded shape uses is missing, or `id` has the wrong type (warns on a column the shape does not have) |
| scopes | a scope names a class instead of a morph alias (fails) or an alias the store does not list (warns) |
| categories | a row points at a category of another store |

It exits non-zero on a failure, so it can gate a deploy. `--json` prints a machine-readable
report.

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
`prune-changes` in [Approvals](approvals.md); `openapi` in [REST API](rest-api.md). Each runs as the
system actor, is audited under its name, and delegates to the same action as the PHP API.

`doctor` also checks, per database store: encrypted values no current or previous key can read,
open change requests past their expiry, schema drift on the history, change requests and
persisted configuration tables, and persisted configuration entries skipped as invalid.

## Pruning and access

Both prune commands run as the system actor and are audited (a `prune` entry recording what was
removed). Their actions, `Actions\PruneOrphans` and `Actions\PruneAudit`, need the `prune`
ability when called with an actor; pruning orphans also needs `forget` for each owner it flushes.

---

[← Docs index](../../README.md#documentation)
