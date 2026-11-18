<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Configurator\SettingsSetup;

/**
 * The application's one configurator: how the package is set up, in code, for every request, job,
 * command and migration. Register it once from a service provider's `register()` with
 * `Settings::useConfigurator(YourConfigurator::class)`; the package builds it through the
 * container and calls `configure()` while the application boots.
 */
interface SettingsConfigurator
{
    public function configure(SettingsSetup $settings): void;
}
