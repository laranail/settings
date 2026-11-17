<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;

/** Depends on a binding from a provider registered after the one that registers it. */
final readonly class GreetingConfigurator implements SettingsConfigurator
{
    public function __construct(private Greeting $greeting) {}

    public function configure(SettingsSetup $settings): void
    {
        $settings->defaultStore($this->greeting->store);
    }
}
