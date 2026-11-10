<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| laranail/settings
|--------------------------------------------------------------------------
|
| Read at `config('laranail.settings.*')` and published to
| `config/laranail/settings.php` with:
|
|     php artisan laranail::settings.install
|
| The default store keeps its values in the database (the install command
| above publishes the package's one migration; then run `php artisan
| migrate`), above a JSON runtime file and the shipped PHP defaults. An
| application without a database drops `database` from the store's
| `layers`.
|
*/

return [

    /*
    | The store used when a call names neither a store nor an owner.
    */
    'default' => env('LARANAIL_SETTINGS_STORE', 'settings'),

    /*
    | Stores. `layers` are read in order; writes go to the first writable one.
    | `owners` lists the morph aliases this store holds values for (each alias
    | belongs to one store). `strict` makes keys without a definition an error.
    | `inherits` lets owners fall back to their parents and to the global value
    | when a definition does not say otherwise. `locks.overridable` lets an
    | actor holding `override_lock` write a locked setting; off, a lock binds
    | everyone. `table` names the store's database table (default: the store
    | name). `bootstrap` lets the database layer be skipped while, and only
    | while, the application is being installed (see `bootstrap` below);
    | otherwise an unreachable database raises DriverUnavailable.
    |
    | Schema switches per store (migration-time, recorded in the schema file):
    | `mode` is `polymorphic` (settable_type/settable_id, any listed alias, plus
    | global rows) or `dedicated` (one owner alias, a non-null `owner_id` foreign
    | key to `owner.table`, owner rows only; its global values come from
    | `fallback`, default the default store). `soft_deletes` keeps forgotten rows
    | as trashed and restores them on the next write.
    |
    |     'user_settings' => [
    |         'layers' => ['database'],
    |         'owners' => ['user'],
    |         'mode'   => 'dedicated',
    |         'owner'  => ['table' => 'users', 'key' => 'id', 'type' => null],
    |         'soft_deletes' => false,
    |     ],
    */
    'stores' => [
        'settings' => [
            'layers'       => ['database', 'runtime', 'shipped'],
            'owners'       => [],
            'strict'       => false,
            'inherits'     => false,
            'locks'        => ['overridable' => false],
            'bootstrap'    => false,
            'mode'         => 'polymorphic',
            'soft_deletes' => false,
        ],
    ],

    /*
    | Positive evidence that the application is being installed, for stores in
    | bootstrap mode: set it for the install window only. laranail/installer-
    | headless reporting an install in progress counts too. The absence of
    | evidence never does.
    */
    'bootstrap' => env('LARANAIL_SETTINGS_BOOTSTRAP', false),

    /*
    | Table names (D-4). `prefix` goes in front of every table, including each
    | store's values table. Install refuses to run over a table of the same
    | name that is not this package's.
    */
    'tables' => [
        'prefix'      => env('LARANAIL_SETTINGS_TABLE_PREFIX', ''),
        'categories'  => 'settings_categories',
        'audit'       => 'settings_audit',
        'definitions' => 'settings_definitions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema switches (migration-time)
    |--------------------------------------------------------------------------
    |
    | Read by `install` and `make-store`, which record the shape the package
    | migration produces in the schema file (`schema.path`, default
    | database/settings-schema.php); the migration reads that file.
    | The package then reads that file, not these keys, so changing a switch
    | after migrating changes nothing until a migration says so; `doctor`
    | reports the drift. The defaults are the recommended shape.
    |
    | identity.mode: `slug_uid` (a slug shared by a key's rows and a ULID per
    | row), `slug_per_row` (a unique sha1 slug per row, no uid) or `uid_only`.
    | ids.type: `bigint`, `uuid` or `ulid`; null follows laranail.db-tools.id_type.
    | ids.owner_key: `string` (settable_id as string(36)) or `typed` (typed like
    | laranail.db-tools.id_type, for owners that share one key type).
    | translations.mode: `lang` (titles, labels and descriptions as text) or
    | `json` (per-locale JSON maps).
    | categories.soft_deletes: categories are trashed, and restored when the slug
    | is created again.
    */
    'schema' => [
        'path' => env('LARANAIL_SETTINGS_SCHEMA_PATH'),
    ],

    'identity' => [
        'mode' => 'slug_uid',
    ],

    'ids' => [
        'type'      => null,
        'owner_key' => 'string',
    ],

    'translations' => [
        'mode' => 'lang',
    ],

    'categories' => [
        'soft_deletes' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | mode (migration-time, recorded in the schema file):
    |   `none`     one set of settings for the application (the default);
    |   `column`   one database: the values, categories and audit tables get a
    |              `tenant` column, every row is stamped with the current tenant
    |              and every query is limited to it ('' is the central rows);
    |   `database` a database per tenant, switched by the tenancy package: no
    |              tenant column; publish the migration into the tenant
    |              migrations folder.
    | discriminator: who the current tenant is. `auto` uses stancl/tenancy or
    | spatie/laravel-multitenancy when installed (neither is required), else
    | none; or `null`, `stancl`, `spatie`, or a class implementing
    | Simtabi\Laranail\Settings\Contracts\TenantDiscriminator. In every mode
    | the tenant also keys what is held in memory during a request or job.
    | Definitions are shared by every tenant.
    */
    'tenancy' => [
        'mode'          => env('LARANAIL_SETTINGS_TENANCY', 'none'),
        'discriminator' => 'auto',
    ],

    /*
    | Default data, seeded with the application's `php artisan db:seed` (or
    | `db:seed --class="Simtabi\Laranail\Settings\Database\Seeders\DefaultCategoriesSeeder"`).
    | `categories` are created as system categories in each of `stores` (empty:
    | the default store), as the system actor, so they are validated and
    | audited; one that already exists is left as it is, so seeding twice
    | changes nothing. A category is `slug => title` or `slug => [title,
    | description, icon, parent, sort_order, translations]`. No setting values
    | and never any credentials are seeded. `enabled` false switches it off.
    */
    'seed' => [
        'enabled'    => env('LARANAIL_SETTINGS_SEED', true),
        'stores'     => [],
        'categories' => [
            'general'       => ['title' => 'General', 'description' => 'Site name, contact details and other application-wide settings.', 'icon' => 'cog'],
            'appearance'    => ['title' => 'Appearance', 'description' => 'Theme, branding and layout.', 'icon' => 'swatch'],
            'localisation'  => ['title' => 'Localisation', 'description' => 'Language, time zone, date and number formats.', 'icon' => 'globe'],
            'mail'          => ['title' => 'Mail', 'description' => 'Sender name and address, and other mail options.', 'icon' => 'envelope'],
            'notifications' => ['title' => 'Notifications', 'description' => 'Which notifications are sent, and how.', 'icon' => 'bell'],
            'security'      => ['title' => 'Security', 'description' => 'Sign-in, sessions and other security options.', 'icon' => 'shield'],
        ],
    ],

    /*
    | Writes. `retry_on_conflict` retries a plain set() once when another writer
    | changed the row between the read and the write (re-reading it under a row
    | lock where the database supports one). A write that names the version it
    | expects is never retried.
    */
    'writes' => [
        'retry_on_conflict' => true,
    ],

    /*
    | The settings cache (database layers only; file layers are read directly).
    | It holds each scope's raw rows (a "bag"), keyed by connection, database,
    | tenant, store and scope, and is invalidated on every write by replacing a
    | generation token, so a write is visible on the next read everywhere.
    | `store` is a cache store from config/cache.php (null: the default one;
    | it must exist, and a node-local store such as `array` or `file` is only
    | right for a single server). `ttl` is how long a bag is fresh; for `stale`
    | seconds after that it is still answered while it is refreshed in the
    | background (Cache::flexible()); 0 turns that off. `prefix` starts every
    | key, so two applications can share a cache store.
    */
    'cache' => [
        'enabled' => env('LARANAIL_SETTINGS_CACHE', true),
        'store'   => env('LARANAIL_SETTINGS_CACHE_STORE'),
        'prefix'  => 'laranail-settings',
        'ttl'     => 3600,
        'stale'   => 300,
    ],

    /*
    | Events after which the schema readiness memo is forgotten, besides
    | migrations, queued jobs and Octane requests: tenant switches. db-tools has
    | no tenant hook of its own, so the tenancy packages' events are listened to
    | by name (no dependency is added; an event that never fires costs nothing).
    */
    'readiness' => [
        'flush_on' => [
            'Stancl\\Tenancy\\Events\\TenancyInitialized',
            'Stancl\\Tenancy\\Events\\TenancyEnded',
            'Spatie\\Multitenancy\\Events\\MadeTenantCurrentEvent',
            'Spatie\\Multitenancy\\Events\\ForgotCurrentTenantEvent',
        ],
    ],

    /*
    | Layers, by name. Drivers: database, json, php, config, array, or a custom
    | one registered with DriverFactory::extend(). A JSON path must contain
    | {store}. A database layer's `connection` is null for the default one.
    */
    'layers' => [
        'database' => [
            'driver'     => 'database',
            'connection' => env('LARANAIL_SETTINGS_DB_CONNECTION'),
        ],
        'runtime' => [
            'driver' => 'json',
            'disk'   => env('LARANAIL_SETTINGS_RUNTIME_DISK', 'local'),
            'path'   => 'laranail/settings/{store}.json',
        ],
        'shipped' => [
            'driver'   => 'php',
            'path'     => 'settings/defaults.php',
            'writable' => false,
        ],
    ],

    /*
    | Definitions, `key => attributes`: type, default, rules, encrypted,
    | public, locked, localised, inherits, overridable_by, precedence,
    | label, description, category, group, store. Definitions can also be
    | registered in code through DefinitionRegistry::define().
    |
    | Three names are options, not keys (D-6): `table` creates the optional
    | settings_definitions table (migration-time), `sources` lists where
    | definitions come from, merged in order, later wins per field (`code`,
    | `config`, `database`; `database` is read only when the table exists),
    | and `allowed_rules` filters the rules of database definitions.
    */
    'definitions' => [
        'table'         => false,
        'sources'       => ['code', 'config', 'database'],
        'allowed_rules' => ['required', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array', 'email', 'url', 'in', 'not_in', 'min', 'max', 'between', 'size', 'regex', 'date', 'alpha', 'alpha_dash', 'alpha_num'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access: actors, authorization, verification, audit, logging
    |--------------------------------------------------------------------------
    |
    | A call made with an actor (`Settings::as($user)`) is authorized; a call
    | without one runs as trusted code and is audited as `system`.
    |
    */

    /*
    | How `asCurrentActor()` finds the actor: `guard` (the first of `guards`
    | with a user; empty means the default guard), `sanctum` (also reads the
    | token's abilities; needs laravel/sanctum), or a class implementing
    | ActorResolver.
    */
    'authentication' => [
        'resolver' => env('LARANAIL_SETTINGS_ACTOR_RESOLVER', 'guard'),
        'guards'   => [],
    ],

    /*
    | `driver`: `gate` (asks `laranail-settings.<ability>`), `permissions`
    | (asks `$user->can()` with the names below), a class implementing
    | Authorizer, or a list of these tried in order. `undefined` decides an
    | ability nobody defined: `deny` (fails closed) or `allow`.
    | `require_actor` refuses calls without an actor; `asSystem()` still works.
    | Permission names may use {ability}, {store} and {key}.
    */
    'authorization' => [
        'driver'        => env('LARANAIL_SETTINGS_AUTHORIZER', 'gate'),
        'undefined'     => 'deny',
        'require_actor' => env('LARANAIL_SETTINGS_REQUIRE_ACTOR', false),
        'permissions'   => [
            'view'               => 'settings.view',
            'view_sensitive'     => 'settings.view_sensitive',
            'update'             => 'settings.update',
            'forget'             => 'settings.forget',
            'lock'               => 'settings.lock',
            'unlock'             => 'settings.unlock',
            'override_lock'      => 'settings.override_lock',
            'approve'            => 'settings.approve',
            'view_history'       => 'settings.view_history',
            'rollback'           => 'settings.rollback',
            'view_audit'         => 'settings.view_audit',
            'export'             => 'settings.export',
            'import'             => 'settings.import',
            'promote'            => 'settings.promote',
            'manage_categories'  => 'settings.manage_categories',
            'manage_definitions' => 'settings.manage_definitions',
            'manage_stores'      => 'settings.manage_stores',
            'prune'              => 'settings.prune',
        ],
    ],

    /*
    | Step-up verification for the abilities listed here and for definitions
    | marked requiresVerification(). `driver`: `password_confirmation` (the
    | session's password confirmation within auth.password_timeout, else the
    | actor's verifiedAt), `actor` (the actor's verifiedAt within `ttl`
    | seconds), `none`, or a class implementing Verifier.
    */
    'verification' => [
        'driver'    => env('LARANAIL_SETTINGS_VERIFIER', 'password_confirmation'),
        'abilities' => ['override_lock', 'rollback', 'import', 'manage_stores'],
        'ttl'       => 900,
    ],

    /*
    | Audit sinks, all of which receive every entry: `auto` (the `database`
    | sink for a store with an active database layer, `log` otherwise),
    | `database` (the settings_audit table), `log` (the logging channel
    | below), `null`, or a class implementing AuditSink. Entries record actions
    | and denials, never values. An allowed write and its database audit row
    | commit together. `keep_days` is the retention prune-audit applies.
    */
    'audit' => [
        'sinks'     => ['auto'],
        'keep_days' => 365,
    ],

    /*
    | The operational log (denials, verification failures, configuration
    | changes, log-sink audit entries). `channel`: null for the application's
    | default channel, a channel name, or false to switch it off.
    */
    'logging' => [
        'channel' => null,
        'level'   => env('LARANAIL_SETTINGS_LOG_LEVEL', 'info'),
    ],

];
