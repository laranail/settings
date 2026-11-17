<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Facades\Settings;

/** Registers from boot(), which is too late. */
final class BootTimeConfiguratorProvider extends ServiceProvider
{
    public function boot(): void
    {
        Settings::useConfigurator(PrefixConfigurator::class);
    }
}
