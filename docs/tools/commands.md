# Commands

Eight Artisan commands, all named `laranail::settings.<command>`.

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

## Pruning and access

Both prune commands run as the system actor and are audited (a `prune` entry recording what was
removed). Their actions, `Actions\PruneOrphans` and `Actions\PruneAudit`, need the `prune`
ability when called with an actor; pruning orphans also needs `forget` for each owner it flushes.

---

[← Docs index](../../README.md#documentation)
