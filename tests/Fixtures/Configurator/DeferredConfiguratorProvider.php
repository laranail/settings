<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Facades\Settings;

/** A deferred provider registers after the application booted, so it cannot register a configurator. */
final class DeferredConfiguratorProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        Settings::useConfigurator(PrefixConfigurator::class);
        $this->app->instance('laranail-settings-test.deferred', true);
    }

    /** @return list<string> */
    public function provides(): array
    {
        return ['laranail-settings-test.deferred'];
    }
}
