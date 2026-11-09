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
| Stores, layers and their drivers arrive with the engine (design phase 1).
| Only keys the package actually reads live here, so a published copy never
| advertises a setting that does nothing.
|
*/

return [

    /*
    | The store used when a call does not name one.
    */
    'default' => env('LARANAIL_SETTINGS_STORE', 'settings'),

];
