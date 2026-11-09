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
| The default store keeps its values in the database (publish and run the
| migrations with the install command above), above a JSON runtime file and
| the shipped PHP defaults. An application without a database drops
| `database` from the store's `layers`.
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
    */
    'stores' => [
        'settings' => [
            'layers'    => ['database', 'runtime', 'shipped'],
            'owners'    => [],
            'strict'    => false,
            'inherits'  => false,
            'locks'     => ['overridable' => false],
            'bootstrap' => false,
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
        'prefix'     => env('LARANAIL_SETTINGS_TABLE_PREFIX', ''),
        'categories' => 'settings_categories',
        'audit'      => 'settings_audit',
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
    */
    'definitions' => [],

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
