# Database

The `database` driver keeps a store's values in a table, through the query builder, with version-checked writes and failures that never fall back to files.

## Tables

| Table | Holds |
|---|---|
| `settings` (each store's own, `stores.<name>.table`) | one row per (scope, key, locale) |
| `settings_categories` | categories of every store on the connection |
| `settings_definitions` | definitions synced from code and edited at runtime, on the default store's connection |
| `settings_audit` | the audit trail of every store on the connection |
| `settings_history` | every value by version, of every store on the connection; see [History](history.md) |
| `settings_change_requests` | writes waiting for approval; see [Approvals](approvals.md) |
| `settings_stores` | stores and layers saved through the API, on the default store's connection |

`tables.prefix` goes in front of all of them. Every connection a store uses has its own
categories, audit, history and change requests tables, so a store's foreign keys and transactions
never cross connections. One migration creates them all, in that order (`down()` in reverse): see
[Installation](../installation.md). In column-mode [tenancy](tenancy.md) every table but
`settings_definitions` and `settings_stores` has a `tenant` column that leads its keys.

The shape is fixed: every table is keyed by a ULID, and titles, labels and descriptions are JSON
maps of locale to text, with `""` for the text that applies to any locale. The values table:

| Column | Notes |
|---|---|
| `id` | a ULID |
| `scope` | `global` or `alias:id`; never null, so it can be part of the unique key |
| `settable_type`, `settable_id` | the owner's morph alias and id (`string(36)`, so integer, UUID and ULID model keys all fit), for reporting and your own `morphTo` |
| `key`, `locale` | with `scope`, the identity: `unique(scope, key, locale)` |
| `uid` | a ULID per row, unique; the history refers to a row by it |
| `group` | the key's first segment unless the definition names one; at most 64 characters |
| `value` | the encoded (and, for encrypted settings, encrypted) value; `NULL` means no value on this row |
| `category_id`, `category_store` | the category, always of the row's own store (composite foreign key) |
| `is_encrypted`, `is_locked`, `is_public`, `type` | from the definition and the lock API |
| `version` | starts at 1, raised by every write |
| `deleted_at` | set on a forgotten row in a store with `soft_deletes` |

`scope`, `key` and `locale` use a binary collation (`utf8mb4_bin` on MySQL, `"C"` on Postgres),
so `Theme` and `theme` never become one row on one database only. Keys are lower-cased before
they reach the table anyway.

## Dedicated stores

```php
'stores' => [
    'settings' => ['layers' => ['database', 'runtime', 'shipped'], 'owners' => ['team']],
    'user_settings' => [
        'layers'   => ['database'],
        'owners'   => ['user'],                                     // exactly one alias
        'mode'     => 'dedicated',
        'owner'    => ['table' => 'users', 'key' => 'id', 'type' => null], // type: bigint, uuid, ulid; null follows db-tools
        'fallback' => 'settings',                                   // default: the default store
        'inherits' => true,
    ],
],
```

A dedicated store's table has an `owner_id` column in place of `settable_type` and
`settable_id`: a foreign key to the owner table with `cascadeOnDelete`, so deleting a user
removes its settings in the database itself. It holds owner rows only: a global write raises
`OwnerNotAllowed` naming the fallback store, and the global step of the cascade, and a read with
no owner, read the fallback store. The fallback must be a configured polymorphic store; a
dedicated store without exactly one owner alias or an owner table is refused when the store
registry is built. Configure it before migrating: the migration reads `mode` and `owner`.

## Soft deletes

With `stores.<name>.soft_deletes`, `forget()` and `flush()` set `deleted_at`; reads, lock checks
and orphan pruning skip trashed rows. The next write to a trashed key restores that row through
the same version check as any update: the writer continues the trashed row's version, the update
clears `deleted_at` and resets `is_locked`, labels, `meta` and the category to the new write. So
the unique index never collides, a restore is never read as a conflict, and a forgotten lock
never comes back. `lock()` on a trashed key restores the row as a value-less lock. Every values
table has the `deleted_at` column, so the option can be turned on at any time.

## The definitions table

`laranail::settings.sync-definitions` (also run by `install` once the table exists, and by
`doctor --fix`) copies code and config definitions in as read-only `is_system` rows, so UIs can
list them; system rows nothing defines any more are removed.

Other rows are definitions edited at runtime. With `database` in `definitions.sources` they answer
for keys no code or config definition covers, so a row can never loosen a coded setting, and a row
sharing a coded key is reported as a conflict and left alone. A row's rules are filtered through
`definitions.allowed_rules` (no `exists`, `unique` or rule classes), its type must be a registered
caster alias, and an encrypted definition's default is never copied into the table.

## Reads

A read loads every scope it needs from a store in one query (`whereIn('scope', …)`), whatever the
number of parents in the cascade. Lists of owners preload theirs:

```php
Settings::preload($users);                       // Settable models or OwnerRef
User::query()->withSettings()->paginate();       // with HasSettings
```

Each store is then read once for the whole list, and reading each owner afterwards costs no query.

## Writes and concurrency

A write updates the row at the version it read. If no row is at that version, it inserts; a row
at another version makes the insert hit the unique index, which is a concurrent change. A plain
`set()` then re-reads the row under a row lock and retries once (`writes.retry_on_conflict`,
on by default; the retry is logged); a second conflict, or any conflict on a write that names the
version it expects (`SettingWrite::$expectedVersion`), raises `ConcurrentModification`. The write,
its events and its audit row share one transaction on the store's connection.

With soft deletes a write to a trashed key restores the row through the same version check; see
[Soft deletes](#soft-deletes).

## Failures

| Situation | Exception |
|---|---|
| the connection is unreachable or the table is missing | `DriverUnavailable` |
| any other database error | `DriverFailure` (the original attached as the previous exception) |
| a category of another store | `CategoryStoreMismatch` |

A store never falls back to its files when its database layer fails. The one exception is
bootstrap mode, while an install is in progress: see
[Moving from files to the database](../recipes/moving-from-files-to-the-database.md).

## Owners

`HasSettings` gives a model `settings()`, `withSettings()` and clean-up on delete:

```php
use Simtabi\Laranail\Settings\Concerns\HasSettings;
use Simtabi\Laranail\Settings\Contracts\Settable;

final class User extends Authenticatable implements Settable
{
    use HasSettings;
}
```

The owner is the model's morph alias, never its class name: register one with
`Relation::enforceMorphMap()`. Deleting the model deletes its settings; a soft-deleting model
keeps them until it is force-deleted. Bulk deletes fire no model events, so run
`laranail::settings.prune-orphans` (see [Commands](commands.md)).

---

[← Docs index](../../README.md#documentation)
