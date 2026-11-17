<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;

/**
 * An application module's contribution to the settings setup (an nWidart or InterNACHI module, or
 * any module with its own service provider). Register it from the module's provider `register()`
 * with `Settings::register(YourContributor::class)`. Its identity is `moduleName()`, unique per
 * application. The same limits as a package contributor apply.
 */
interface ModuleSettingsContributor
{
    /** The module's name, unique per application (for example `Billing`). */
    public static function moduleName(): string;

    public function configure(SettingsSetup $settings): void;
}
