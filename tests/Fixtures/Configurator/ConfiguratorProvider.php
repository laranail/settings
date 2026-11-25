<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;
use Simtabi\Laranail\Settings\Facades\Settings;

/** Registers a configurator from register(), as an application's provider would. */
final class ConfiguratorProvider extends ServiceProvider
{
    /** @var class-string<SettingsConfigurator> */
    public static string $configurator = PrefixConfigurator::class;

    public function register(): void
    {
        Settings::useConfigurator(self::$configurator);
    }
}
