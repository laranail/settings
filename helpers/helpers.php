<?php

declare(strict_types=1);

/*
 * laranail/settings: the `settings()` helper, a consumer convenience over the Settings contract.
 *
 * It is a namespaced function, never a global one: a bare global `settings()` is a name many
 * packages and applications already define, and the second definition would silently win (or
 * fatal). Import it where you want it:
 *
 *     use function Simtabi\Laranail\Settings\settings;
 *
 *     settings('site.name');                 // Settings::get('site.name')
 *     settings('site.tagline', 'Welcome');   // with a default
 *     settings()->for($user)->set('ui.theme', 'dark');
 *
 * The package's own classes never call it; they take the contract through their constructors.
 */

namespace Simtabi\Laranail\Settings;

use Illuminate\Container\Container;
use Simtabi\Laranail\Settings\Contracts\Settings;

if (! function_exists(__NAMESPACE__ . '\settings')) {
    /**
     * The settings handle with no key, else the value of `$key` (or `$default`), read as
     * {@see Settings::get()} reads it.
     *
     * @return ($key is null ? Settings : mixed)
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $settings = Container::getInstance()->make(Settings::class);

        return $key === null ? $settings : $settings->get($key, $default);
    }
}
