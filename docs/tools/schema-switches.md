# Schema switches

Eight schema switches choose the shape of the package's tables at migration time; the defaults are the recommended shape, and the package then reads the recorded shape, never live configuration.

## The switches

| Switch | Values (default first) | Effect |
|---|---|---|
| `identity.mode` | `slug_uid`, `slug_per_row`, `uid_only` | which identity columns a values row has besides (scope, key, locale) |
| `ids.type` | `null` (follows `laranail.db-tools.id_type`), `bigint`, `uuid`, `ulid` | the key of every package table and of every foreign key to one |
| `ids.owner_key` | `string`, `typed` | `settable_id` as `string(36)`, or typed like the owners' keys |
| `stores.<name>.mode` | `polymorphic`, `dedicated` | `settable_type` / `settable_id`, or a non-null `owner_id` foreign key to one owner table |
| `stores.<name>.soft_deletes` | `false`, `true` | forgotten rows are trashed, and restored by the next write |
| `translations.mode` | `lang`, `json` | titles, labels and descriptions as text, or as per-locale JSON maps |
| `definitions.table` | `false`, `true` | creates the optional `settings_definitions` table |
| `history.enabled` | `true`, `false` | creates `settings_history` and records every change in it ([History](history.md)) |

`categories.soft_deletes` (default `false`) gives categories the same trash-and-restore rule.
`tables.prefix` and the table names (`tables.categories`, `tables.audit`, `tables.definitions`,
`tables.history`, `stores.<name>.table`) are recorded with the switches and honoured everywhere, including the
check that refuses to install over a table that is not the package's.

## Identity modes

| Mode | `slug` | `uid` |
|---|---|---|
| `slug_uid` | the key with `.` replaced by `-`, shared by every row of the key, indexed | a ULID per row, unique |
| `slug_per_row` | `sha1(scope|key|locale)`, unique per row | none |
| `uid_only` | none | a ULID per row, unique |

## Keys and owner keys

With `uuid` or `ulid` the package generates every key itself (`Str::orderedUuid()`,
`Str::ulid()`); `category_id`, `parent_id` and the definitions table's `category_id` take the same
type, so the composite foreign key from a values table to its store's categories always matches.

`ids.owner_key = typed` types `settable_id` like `laranail.db-tools.id_type` (the key type of the
application's models). A write for an owner id of another shape (`user:abc` against bigint keys)
raises `OwnerNotAllowed`. The package itself never queries by `settable_id`: every read goes
through `scope`, so the owner key type never meets the column type (G-26).

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

A dedicated store's `owner_id` is a foreign key to the owner table with `cascadeOnDelete`, so
deleting a user removes its settings in the database itself. It holds owner rows only: a global
write raises `OwnerNotAllowed` naming the fallback store, and the global step of the cascade, and
a read with no owner, read the fallback store. The fallback must be a configured polymorphic
store; a dedicated store without exactly one owner alias or an owner table is refused when the
store registry is built.

## Soft deletes

With `stores.<name>.soft_deletes`, `forget()` and `flush()` set `deleted_at`; reads, lock checks
and orphan pruning skip trashed rows. The next write to a trashed key restores that row through
the same version check as any update: the writer continues the trashed row's version, the update
clears `deleted_at` and resets `is_locked`, labels, `meta` and the category to the new write. So
the unique index never collides, a restore is never read as a conflict, and a forgotten lock
never comes back. `lock()` on a trashed key restores the row as a value-less lock.

Under `categories.soft_deletes`, deleting a category trashes it (its children move to the top
level and its settings lose the link, as before), and creating the same slug again restores the
row with the same id.

## JSON translations

Under `translations.mode = json`, a category's title is stored as a JSON map with `""` for the
title that applies to any locale:

```php
$settings->categories()->create(new NewCategory('mail', 'Mail', translations: ['fr' => 'Courrier']));
$settings->categories()->get('mail')->titleIn('fr_CA');   // "Courrier" (locale, then language, then "")
```

Labels and descriptions in the definitions table are stored the same way. Under `lang`, titles are
plain text or translation keys and `translations` is ignored.

## The definitions table

`definitions.table = true` creates `settings_definitions` (§4.4 of the design). Code and config
definitions are copied in as read-only `is_system` rows by
`laranail::settings.sync-definitions` (also run by `install` once the table exists, and by
`doctor --fix`), so UIs can list them; system rows nothing defines any more are removed.

Other rows are definitions edited at runtime. They answer only for keys no code or config
definition covers, so a row can never loosen a coded setting, and a row sharing a coded key is
reported as a conflict and left alone. A row's rules are filtered through
`definitions.allowed_rules` (no `exists`, `unique` or rule classes), its type must be a
registered caster alias, and an encrypted definition's default is never copied into the table.
`definitions.sources` (`code`, `config`, `database`) turns the config map or the table off.

## The schema file

`install` writes `database/settings-schema.php` (or `schema.path`): a `shape_version`, the
switches (`tenancy` included), the table names and one entry per database store. `make-store`
adds its store's entry. The package migration and the runtime read this file, not configuration, so changing a switch after
migrating changes nothing until a migration does. Commit it. An existing record always wins:
recording again only adds stores that are new.

`doctor` reports drift three ways: configuration against the file (a warning naming each
difference), the file's `shape_version` against this release (a release that adds columns raises
it; an older file means an upgrade migration was never published), and the live tables against
the file (a missing column fails, a column the shape does not have warns, an `id` of the wrong
type fails). A database store with no entry in the file fails.

## Keeping the switches tested

`tests/Feature/Database/SchemaMatrixTest.php` builds a pairwise set of shapes (every pair of
switch values meets in at least one; 11 shapes covering all 217 pairs of the ten switches,
`tenancy.mode` none and column and `history.enabled` on and off included, both counts asserted)
and runs each end to end, inside a tenant in column mode, reading the history where it is on, and
finishing with `doctor`. It runs on SQLite with the suite and on MySQL and PostgreSQL in the
`database` group; `LARANAIL_SETTINGS_MATRIX=full` runs all 2,304 shapes, which the weekly CI job
does. A schema file recorded before `history.enabled` existed reads as history off. (`database` mode adds no column, so it is not a separate shape.)

---

[← Docs index](../../README.md#documentation)
