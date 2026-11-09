# Commands

Seven Artisan commands, all named `laranail::settings.<command>`.

## The commands

| Command | Does |
|---|---|
| `laranail::settings.install` | publishes the config and the migrations; refuses to run over a foreign table of the same name |
| `laranail::settings.make-store {store} [--owner=*] [--connection=]` | writes a new store's migration and prints its configuration entry |
| `laranail::settings.promote [store] [--force] [--from=]` | copies values written before install from the runtime file into the database |
| `laranail::settings.doctor [--json]` | checks readiness, schema drift and rows the database cannot guard |
| `laranail::settings.prune-orphans [--dry-run]` | removes the settings of owners that no longer exist |
| `laranail::settings.prune-audit [--days=]` | removes audit rows older than `audit.keep_days` |

Each one delegates to an action (`Actions\PromoteStore`, `Actions\PruneOrphans`, …) that the PHP
API uses too, so the console and the API cannot behave differently.

## `make-store`

```bash
php artisan laranail::settings.make-store user_settings --owner=user
php artisan migrate
```

It writes `…_create_settings_user_settings_table.php` and prints the entry to add under
`stores`. With `--connection=archive` it also writes the categories and audit tables for that
connection and prints the layer to add. It refuses a store that already has a migration.

## `doctor`

For every store with a database layer:

| Check | Fails when |
|---|---|
| readiness | the connection does not answer or the table is missing (a warning in bootstrap mode) |
| schema | a column this release uses is missing: publish and run the latest migrations |
| scopes | a scope names a class instead of a morph alias (fails) or an alias the store does not list (warns) |
| categories | a row points at a category of another store |

It exits non-zero on a failure, so it can gate a deploy. `--json` prints a machine-readable
report.

## `prune-orphans`

Lists, per store and alias, the owner scopes whose model no longer exists (soft-deleted models
count as existing) and removes their rows. An alias with no model in the morph map is reported and
left alone. Schedule it if you delete owners in bulk.

---

[← Docs index](../../README.md#documentation)
