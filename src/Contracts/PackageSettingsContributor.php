<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;

/**
 * A Composer package's contribution to the settings setup: stores, layers and other entries it
 * owns. Register it from the package's service provider `register()` with
 * `Settings::register(YourContributor::class)`. Its identity is the Composer package the class is
 * installed in. It may not set application-wide decisions (tables, tenancy, HTTP, the default
 * store or the mode), and it never overwrites an entry another contributor or the application owns.
 * In strict mode the application applies none of it.
 */
interface PackageSettingsContributor
{
    public function configure(SettingsSetup $settings): void;
}
