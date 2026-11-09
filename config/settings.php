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
| The database layer arrives in the next release; until then a store reads
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
    | when a definition does not say otherwise.
    */
    'stores' => [
        'settings' => [
            'layers'   => ['runtime', 'shipped'],
            'owners'   => [],
            'strict'   => false,
            'inherits' => false,
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

];
