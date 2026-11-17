<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Facades\Settings;

/** A second registration, which must be refused. */
final class SecondConfiguratorProvider extends ServiceProvider
{
    public function register(): void
    {
        Settings::useConfigurator(GreetingConfigurator::class);
    }
}
