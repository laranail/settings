<?php

declare(strict_types=1);

/*
 * The `settings()` helper. It is namespaced because a global `settings()` would collide with
 * other packages. Import it where you want it:
 *
 *     use function Simtabi\Laranail\Settings\settings;
 *
 *     settings('site.name');                 // Settings::get('site.name')
 *     settings('site.tagline', 'Welcome');   // with a default
 *     settings()->for($user)->set('ui.theme', 'dark');
 */

namespace Simtabi\Laranail\Settings;

use Illuminate\Container\Container;
use Simtabi\Laranail\Settings\Contracts\Settings;

if (! function_exists(__NAMESPACE__ . '\settings')) {
    /**
     * The settings handle, or the value of `$key` when one is given.
     *
     * @return ($key is null ? Settings : mixed)
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $settings = Container::getInstance()->make(Settings::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}
