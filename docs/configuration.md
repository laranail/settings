# Configuration

Every key in `config/laranail/settings.php`, read at `config('laranail.settings.*')`.

## Keys

| Key | Default | Purpose |
|---|---|---|
| `default` | `settings` (`LARANAIL_SETTINGS_STORE`) | the store used when a call names neither a store nor an owner |
| `stores.<name>.layers` | `['database', 'runtime', 'shipped']` | layer names, in read order; writes go to the first writable one |
| `stores.<name>.owners` | `[]` | morph aliases this store holds values for; each alias belongs to one store |
| `stores.<name>.strict` | `false` | refuse keys without a definition |
| `stores.<name>.inherits` | `false` | let owners fall back to parents and the global value for keys whose definition does not say otherwise |
| `stores.<name>.locks.overridable` | `false` | let an actor allowed `override_lock` write a locked setting |
| `stores.<name>.table` | the store name | the store's values table, after `tables.prefix` |
| `stores.<name>.bootstrap` | `false` | skip the database layer while, and only while, there is evidence of an install in progress |
| `bootstrap` | `false` (`LARANAIL_SETTINGS_BOOTSTRAP`) | evidence of an install in progress; set it for the install window only |
| `tables.prefix` | `''` (`LARANAIL_SETTINGS_TABLE_PREFIX`) | put in front of every package table, `[a-z0-9_]`, at most 32 characters |
| `tables.categories` | `settings_categories` | the categories table, one per connection |
| `tables.audit` | `settings_audit` | the audit table, one per connection |
| `tables.definitions` | `settings_definitions` | the optional definitions table |
| `stores.<name>.mode` | `polymorphic` | `dedicated`: one owner alias, an `owner_id` foreign key to `owner.table`, owner rows only (migration-time) |
| `stores.<name>.owner` | — | dedicated stores: `table`, `key` (`id`), `type` (`bigint`/`uuid`/`ulid`; null follows db-tools) |
| `stores.<name>.fallback` | the default store | dedicated stores: the polymorphic store that holds their global values |
| `stores.<name>.soft_deletes` | `false` | trash forgotten rows and restore them on the next write (migration-time) |
| `schema.path` | `database/settings-schema.php` (`LARANAIL_SETTINGS_SCHEMA_PATH`) | where the recorded schema shape lives |
| `identity.mode` | `slug_uid` | `slug_uid`, `slug_per_row` or `uid_only` (migration-time) |
| `ids.type` | `null` | `bigint`, `uuid` or `ulid`; null follows `laranail.db-tools.id_type` (migration-time) |
| `ids.owner_key` | `string` | `typed` types `settable_id` like the owners' keys (migration-time) |
| `translations.mode` | `lang` | `json` stores titles, labels and descriptions per locale (migration-time) |
| `categories.soft_deletes` | `false` | trash deleted categories and restore them when the slug is created again (migration-time) |
| `writes.retry_on_conflict` | `true` | retry a plain `set()` once after a concurrent change |
| `readiness.flush_on` | the stancl and spatie tenancy events | events after which schema readiness is re-checked |
| `tenancy.mode` | `none` (`LARANAIL_SETTINGS_TENANCY`) | `column` adds a `tenant` column to the values, categories and audit tables; `database` relies on a database per tenant (migration-time) |
| `tenancy.discriminator` | `auto` | who the current tenant is: `auto` (stancl/tenancy, then spatie/laravel-multitenancy, when installed), `null`, `stancl`, `spatie`, or a class implementing `TenantDiscriminator` |
| `seed.enabled` | `true` (`LARANAIL_SETTINGS_SEED`) | whether `db:seed` creates the default categories |
| `seed.stores` | `[]` | the stores to seed; empty means the default store |
| `seed.categories` | general, appearance, localisation, mail, notifications, security | `slug => title` or `slug => [title, description, icon, parent, sort_order, translations]` |
| `layers.<name>.driver` | — | `database`, `json`, `php`, `config`, `array`, or a custom driver |
| `layers.<name>.connection` | `null` (`LARANAIL_SETTINGS_DB_CONNECTION` for `database`) | database driver: the connection; null is the default one |
| `layers.<name>.writable` | `true` | set `false` for a read-only layer |
| `layers.<name>.disk` | `local` (`LARANAIL_SETTINGS_RUNTIME_DISK`) | JSON driver: a private filesystem disk |
| `layers.<name>.path` | — | JSON: a path on the disk containing `{store}`; PHP: a path from the application root |
| `layers.<name>.prefix` | — | config driver: the config prefix to read |
| `definitions` | options only | `key => attributes`; see [Definitions](tools/definitions.md). `table`, `sources` and `allowed_rules` are options, not keys |
| `definitions.table` | `false` | create the optional definitions table (migration-time) |
| `definitions.sources` | `code`, `config`, `database` | where definitions come from; `database` is read only when the table exists |
| `definitions.allowed_rules` | common rule names | the rules a definitions-table row may use |
| `authentication.resolver` | `guard` (`LARANAIL_SETTINGS_ACTOR_RESOLVER`) | how `asCurrentActor()` finds the actor: `guard`, `sanctum` or a class |
| `authentication.guards` | `[]` | guards tried in order; empty means the default guard |
| `authorization.driver` | `gate` (`LARANAIL_SETTINGS_AUTHORIZER`) | `gate`, `permissions`, a class, or a list tried in order |
| `authorization.undefined` | `deny` | decides an ability nobody defined: `deny` or `allow` |
| `authorization.require_actor` | `false` (`LARANAIL_SETTINGS_REQUIRE_ACTOR`) | refuse calls without an actor; `asSystem()` still works |
| `authorization.permissions` | `settings.<ability>` for each ability, `prune` included | permission names for the `permissions` driver; `{ability}`, `{store}`, `{key}` |
| `verification.driver` | `password_confirmation` (`LARANAIL_SETTINGS_VERIFIER`) | `password_confirmation`, `actor`, `none` or a class |
| `verification.abilities` | `override_lock`, `rollback`, `import`, `manage_stores` | abilities that always need step-up verification |
| `verification.ttl` | `900` | seconds an actor's `verifiedAt` stays fresh for the `actor` driver |
| `audit.sinks` | `['auto']` | `auto` (`database` for a store with an active database layer, else `log`), `database`, `log`, `null` or classes; every sink gets every entry |
| `audit.keep_days` | `365` | how long `prune-audit` keeps audit rows |
| `logging.channel` | `null` | the application's default channel, a channel name, or `false` for off |
| `logging.level` | `info` (`LARANAIL_SETTINGS_LOG_LEVEL`) | the lowest level written |

Store names, keys, locales and owner aliases are validated, and a misconfiguration (an unknown driver, sink, verifier, resolver, level or ability included) raises `InvalidConfiguration` naming the config path on first use. The `password_confirmation` verifier also reads Laravel's own `auth.password_timeout`.

Migration-time switches (`tenancy.mode` among them) are read by `install` and `make-store` and recorded in the schema file; after that the package reads the file, and `doctor` reports configuration that differs from it. See [Schema switches](tools/schema-switches.md).

Access, audit and logging are described in [Access control](tools/access-control.md) and [Audit and logging](tools/audit-and-logging.md); per-request changes in [Runtime configuration](tools/runtime-configuration.md).

## Publishing

```bash
php artisan laranail::settings.install
```

The published file at `config/laranail/settings.php` is merged over the package's defaults.

---

[← Docs index](../README.md#documentation)
