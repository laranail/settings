# Database

The `database` driver keeps a store's values in a table, through the query builder, with version-checked writes and failures that never fall back to files.

## Tables

| Table | Holds |
|---|---|
| `settings` (each store's own, `stores.<name>.table`) | one row per (scope, key, locale) |
| `settings_categories` | categories of every store on the connection |
| `settings_audit` | the audit trail of every store on the connection |
| `settings_definitions` | optional (`definitions.table`): definitions synced from code and edited at runtime |

`tables.prefix` goes in front of all of them. Every connection a store uses has its own
categories and audit tables, so a store's foreign keys and transactions never cross connections.
One migration creates them all, in that order (`down()` in reverse): see
[Installation](../installation.md). In column-mode [tenancy](tenancy.md) the values, categories
and audit tables have a `tenant` column that leads their unique keys.

The values table, in the default shape (polymorphic owners, identity mode `slug_uid`, bigint ids,
string owner key). Every other shape is a [schema switch](schema-switches.md): `uuid` / `ulid`
keys, `slug_per_row` or `uid_only` identity, typed owner keys, dedicated stores with an `owner_id`
foreign key, soft deletes, JSON labels, and the optional `settings_definitions` table. The
package reads the shape recorded in `database/settings-schema.php`, not live configuration.

| Column | Notes |
|---|---|
| `scope` | `global` or `alias:id`; never null, so it can be part of the unique key |
| `settable_type`, `settable_id` | the owner's morph alias and id, for reporting and your own `morphTo` |
| `key`, `locale` | with `scope`, the identity: `unique(scope, key, locale)` |
| `group` | the key's first segment unless the definition names one; at most 64 characters |
| `slug` | the key with `.` replaced by `-`, shared by every row of the key |
| `uid` | a ULID per row |
| `value` | the encoded (and, for encrypted settings, encrypted) value; `NULL` means no value on this row |
| `category_id`, `category_store` | the category, always of the row's own store (composite foreign key) |
| `is_encrypted`, `is_locked`, `is_public`, `type` | from the definition and the lock API |
| `version` | starts at 1, raised by every write |

`scope`, `key`, `slug` and `locale` use a binary collation (`utf8mb4_bin` on MySQL, `"C"` on
Postgres), so `Theme` and `theme` never become one row on one database only. Keys are lower-cased
before they reach the table anyway.

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
[Schema switches](schema-switches.md#soft-deletes).

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
