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
| The database layer arrives in a later release; until then a store reads
| and writes its JSON runtime file and reads its shipped PHP defaults.
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
    | everyone.
    */
    'stores' => [
        'settings' => [
            'layers'   => ['runtime', 'shipped'],
            'owners'   => [],
            'strict'   => false,
            'inherits' => false,
            'locks'    => ['overridable' => false],
        ],
    ],

    /*
    | Layers, by name. Drivers: json, php, config, array, or a custom one
    | registered with DriverFactory::extend(). A JSON path must contain {store}.
    */
    'layers' => [
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
    | Audit sinks, all of which receive every entry: `log` (the logging
    | channel below), `null`, or a class implementing AuditSink. Entries
    | record actions and denials, never values. A `database` sink arrives with
    | the database layer.
    */
    'audit' => [
        'sinks' => ['log'],
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
