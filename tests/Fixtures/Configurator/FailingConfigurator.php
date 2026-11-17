<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;

/** Sets an invalid table prefix. */
final class FailingConfigurator implements SettingsConfigurator
{
    public function configure(SettingsSetup $settings): void
    {
        $settings->tables(prefix: 'Not-Valid');
    }
}
