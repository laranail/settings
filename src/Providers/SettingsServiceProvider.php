<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Providers;

use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

/**
 * Registers laranail/settings with the host application.
 *
 * Every name it registers is vendor-scoped: config at `laranail.settings`, publish tags
 * `laranail::settings-*`, and the install command `laranail::settings.install`. Nothing here
 * touches the database or the filesystem at boot.
 */
final class SettingsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/settings')
            ->setPublishTagId('settings')
            ->hasConfigFile('settings')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command->publishConfigFile();
            });
    }
}
