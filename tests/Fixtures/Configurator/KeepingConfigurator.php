<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;

/** Keeps the setup it was given, to change it after boot (which must be refused). */
final class KeepingConfigurator implements SettingsConfigurator
{
    public static ?SettingsSetup $setup = null;

    public function configure(SettingsSetup $settings): void
    {
        self::$setup = $settings->bootstrap(false);
    }
}
