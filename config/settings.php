<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Importers\ToolkitJsonImporter;
use Simtabi\Laranail\Settings\Importers\SpatieSettingsImporter;
use Simtabi\Laranail\Settings\Importers\AkauntingSettingsImporter;

/*
|--------------------------------------------------------------------------
| laranail/settings
|--------------------------------------------------------------------------
|
| Read from `config('laranail.settings.*')`. Publish it to
| `config/laranail/settings.php` with `php artisan laranail::settings.install`,
| which also publishes the migration.
|
*/

return [

    /*
    | The store used when a call names neither a store nor an owner.
    */
    'default' => env('LARANAIL_SETTINGS_STORE', 'settings'),

    /*
    | Stores, by name.
    |
    | layers:        the layers read in order; writes go to the first writable one.
    | owners:        the morph aliases this store holds values for.
    | strict:        reject keys that have no definition.
    | inherits:      let owners fall back to their parents and the global value.
    | locks:         `overridable` lets an actor with `override_lock` write a
    |                locked setting.
    | approvals:     key globs (`mail.*`) whose writes become change requests.
    | bootstrap:     skip the database layer while the application installs.
    | table:         the values table name (default: the store name).
    | mode:          `polymorphic` or `dedicated` (one owner alias, with a
    |                foreign key to `owner.table`; read when migrating).
    | soft_deletes:  keep forgotten rows trashed and restore them on write.
    |
    |     'user_settings' => [
    |         'layers' => ['database'],
    |         'owners' => ['user'],
    |         'mode'   => 'dedicated',
    |         'owner'  => ['table' => 'users', 'key' => 'id', 'type' => null],
    |     ],
    */
    'stores' => [
        'settings' => [
            'layers'       => ['database', 'runtime', 'shipped'],
            'owners'       => [],
            'strict'       => false,
            'inherits'     => false,
            'locks'        => ['overridable' => false],
            'approvals'    => ['keys' => []],
            'bootstrap'    => false,
            'mode'         => 'polymorphic',
            'soft_deletes' => false,
        ],
    ],

    /*
    | Set while the application is being installed, so stores in bootstrap
    | mode skip their database layer. laranail/installer-headless reporting an
    | install in progress counts too.
    */
    'bootstrap' => env('LARANAIL_SETTINGS_BOOTSTRAP', false),

    /*
    | Table names. `prefix` is prepended to every table, including each store's
    | values table.
    */
    'tables' => [
        'prefix'          => env('LARANAIL_SETTINGS_TABLE_PREFIX', ''),
        'categories'      => 'settings_categories',
        'audit'           => 'settings_audit',
        'definitions'     => 'settings_definitions',
        'history'         => 'settings_history',
        'change_requests' => 'settings_change_requests',
        'stores'          => 'settings_stores',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    |
    | mode (read when migrating, and on every query):
    |   `none`      one set of settings for the application.
    |   `column`    a `tenant` column on every table, filled and filtered with
    |               the current tenant ('' is the central partition).
    |   `database`  a database per tenant; publish the migration into the
    |               tenant migrations folder.
    |
    | discriminator: `auto` (stancl/tenancy or spatie/laravel-multitenancy when
    | installed), `null`, `stancl`, `spatie`, or a class implementing
    | Simtabi\Laranail\Settings\Contracts\TenantDiscriminator.
    */
    'tenancy' => [
        'mode'          => env('LARANAIL_SETTINGS_TENANCY', 'none'),
        'discriminator' => 'auto',
    ],

    /*
    | Categories seeded by `php artisan db:seed` as system categories in each of
    | `stores` (empty: the default store). Existing categories are left as they
    | are. A category is `slug => title` or `slug => [title, description, icon,
    | parent, sort_order, translations]`.
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
    | retry_on_conflict: retry a plain set() once when another writer changed
    | the row first. Writes that name an expected version are never retried.
    */
    'writes' => [
        'retry_on_conflict' => true,
    ],

    /*
    | The cache in front of database layers.
    |
    | store:   a cache store from config/cache.php, or null for the default.
    |          Use a shared store when running more than one server.
    | prefix:  prepended to every cache key.
    | ttl:     seconds a cached scope stays fresh.
    | stale:   seconds a stale scope is still served while it refreshes;
    |          0 turns this off.
    */
    'cache' => [
        'enabled' => env('LARANAIL_SETTINGS_CACHE', true),
        'store'   => env('LARANAIL_SETTINGS_CACHE_STORE'),
        'prefix'  => 'laranail-settings',
        'ttl'     => 3600,
        'stale'   => 300,
    ],

    /*
    | Events that reset the schema readiness check, in addition to migrations,
    | queued jobs and Octane requests. Listed by name, so no tenancy package is
    | required.
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
    | Layers, by name. Drivers: database, json, php, config, array, or one
    | registered with DriverFactory::extend(). A JSON path must contain {store};
    | a null database `connection` uses the default connection.
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
    | Setting definitions are registered in code with DefinitionRegistry::define()
    | or kept in the definitions table.
    |
    | sources:       where definitions come from, merged in order, later wins.
    |                Add `database` to read rows edited at runtime from the
    |                definitions table (one query per store per request).
    | allowed_rules: the validation rules a database definition may use.
    */
    'definitions' => [
        'sources'       => ['code', 'config'],
        'allowed_rules' => ['required', 'nullable', 'string', 'integer', 'numeric', 'boolean', 'array', 'email', 'url', 'in', 'not_in', 'min', 'max', 'between', 'size', 'regex', 'date', 'alpha', 'alpha_dash', 'alpha_num'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Access
    |--------------------------------------------------------------------------
    |
    | A call made with an actor (`Settings::as($user)`) is authorized; a call
    | without one runs as trusted code and is audited as `system`.
    |
    | resolver: how asCurrentActor() finds the actor: `guard` (the first of
    | `guards` with a user), `sanctum` (also reads token abilities), or a class
    | implementing ActorResolver.
    */
    'authentication' => [
        'resolver' => env('LARANAIL_SETTINGS_ACTOR_RESOLVER', 'guard'),
        'guards'   => [],
    ],

    /*
    | driver:        `gate` (asks `laranail-settings.<ability>`), `permissions`
    |                (asks `$user->can()` with the names below), a class
    |                implementing Authorizer, or a list tried in order.
    | undefined:     `deny` or `allow` an ability nobody defined.
    | require_actor: refuse calls without an actor; asSystem() still works.
    | permissions:   permission names; may use {ability}, {store} and {key}.
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
    | Step-up verification for these abilities and for definitions marked
    | requiresVerification().
    |
    | driver: `password_confirmation`, `actor` (the actor's verifiedAt within
    |         `ttl` seconds), `none`, or a class implementing Verifier.
    */
    'verification' => [
        'driver'    => env('LARANAIL_SETTINGS_VERIFIER', 'password_confirmation'),
        'abilities' => ['override_lock', 'rollback', 'import', 'manage_stores'],
        'ttl'       => 900,
    ],

    /*
    | sinks:     every sink receives every entry: `auto` (database when the
    |            store has a database layer, else log), `database`, `log`,
    |            `null`, or a class implementing AuditSink.
    | keep_days: retention applied by prune-audit.
    */
    'audit' => [
        'sinks'     => ['auto'],
        'keep_days' => 365,
    ],

    /*
    | The operational log: denials, verification failures and configuration
    | changes. `channel` is null for the default channel, a channel name, or
    | false to turn it off.
    */
    'logging' => [
        'channel' => null,
        'level'   => env('LARANAIL_SETTINGS_LOG_LEVEL', 'info'),
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Every value change is recorded in the history table.
    |
    | keep_days:     retention applied by prune-history; null to disable.
    | keep_versions: versions kept per setting; null to disable.
    */
    'history' => [
        'keep_days'     => 365,
        'keep_versions' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in listeners
    |--------------------------------------------------------------------------
    |
    | alerts: send the alerts configured below (they also need alerts.enabled).
    | log:    write every settings event to the operational log.
    */
    'listeners' => [
        'alerts' => true,
        'log'    => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Notifications sent when an event matches a rule. Alerts never carry a
    | value. Each rule:
    |
    |   'events'         => ['SettingSaved', 'AccessWasDenied'], // short names or classes
    |   'keys'           => ['mail.*'],          // globs; null for any
    |   'stores'         => ['settings'],        // null for any
    |   'sensitive_only' => false,               // only sensitive or encrypted keys
    |   'outcomes'       => ['allowed'],         // allowed, denied; null for any
    |   'notify'         => [
    |       'mail'     => ['ops@example.com'],
    |       'slack'    => env('SETTINGS_ALERTS_SLACK_WEBHOOK'), // needs laravel/slack-notification-channel
    |       'database' => ['type' => App\Models\User::class, 'id' => 1],
    |   ],
    |   'queue'          => null,                // a queue name, or null to send at once
    |
    | throttle: seconds a rule stays quiet about a key after alerting (0: never),
    |           tracked in `cache` (null: the default store).
    */
    'alerts' => [
        'enabled'  => env('LARANAIL_SETTINGS_ALERTS', false),
        'cache'    => null,
        'throttle' => 300,
        'rules'    => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approvals
    |--------------------------------------------------------------------------
    |
    | Writes to definitions marked requiresApproval(), or to keys matched by
    | `stores.<name>.approvals.keys`, become change requests.
    |
    | min_approvals: approvals needed to apply a change.
    | allow_self:    let the requester approve their own request.
    | expire_after:  days before an open request expires.
    | keep_days:     days decided requests are kept by prune-changes.
    */
    'approvals' => [
        'min_approvals' => 1,
        'allow_self'    => false,
        'expire_after'  => 7,
        'keep_days'     => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Persisted configuration
    |--------------------------------------------------------------------------
    |
    | persisted: read and write the stores and layers saved through the API
    |            (the settings_stores table).
    | sources:   merge order, later wins: `config`, `database`, `runtime`.
    */
    'configuration' => [
        'persisted' => env('LARANAIL_SETTINGS_PERSISTED_CONFIGURATION', false),
        'sources'   => ['config', 'database', 'runtime'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Importers
    |--------------------------------------------------------------------------
    |
    | Sources for `laranail::settings.import --from=<name>`: classes implementing
    | Simtabi\Laranail\Settings\Contracts\Importer. None needs the package it
    | reads.
    */
    'importers' => [
        'spatie'    => SpatieSettingsImporter::class,
        'toolkit'   => ToolkitJsonImporter::class,
        'akaunting' => AkauntingSettingsImporter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Typed settings
    |--------------------------------------------------------------------------
    |
    | Classes extending Simtabi\Laranail\Settings\Typed\TypedSettings whose
    | properties are registered as definitions.
    */
    'typed' => [
        'classes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | REST API
    |--------------------------------------------------------------------------
    |
    | enabled:     register the routes (off by default).
    | prefix:      routes live under `prefix`/v1, named laranail-settings.api.*.
    | middleware:  null for ['api', 'auth:sanctum'] when Sanctum is installed,
    |              else ['api', 'auth'].
    | rate_limit:  requests per minute per actor; 0 for none.
    | routes:      switch route groups off.
    | idempotency: how long (`ttl`) and where (`cache`) responses to writes
    |              sent with an Idempotency-Key header are kept.
    | page_size:   items per page in listings.
    |
    | An optional `owners` key maps owner aliases a URL may name to model
    | classes; unset, the morph map is used.
    */
    'http' => [
        'enabled'    => env('LARANAIL_SETTINGS_HTTP', false),
        'prefix'     => env('LARANAIL_SETTINGS_HTTP_PREFIX', 'api/settings'),
        'middleware' => null,
        'rate_limit' => 60,
        'routes'     => [
            'stores'        => true,
            'definitions'   => true,
            'settings'      => true,
            'history'       => true,
            'audit'         => true,
            'approvals'     => true,
            'categories'    => true,
            'configuration' => true,
            'transfer'      => true,
        ],
        'idempotency' => [
            'cache' => null,
            'ttl'   => 86400,
        ],
        'page_size' => 50,
    ],
];
