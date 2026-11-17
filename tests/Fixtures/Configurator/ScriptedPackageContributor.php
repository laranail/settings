<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Closure;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\PackageSettingsContributor;

/** Runs whatever a test sets in $configure. */
final class ScriptedPackageContributor implements PackageSettingsContributor
{
    /** @var (Closure(SettingsSetup): mixed)|null */
    public static ?Closure $configure = null;

    public function configure(SettingsSetup $settings): void
    {
        if (self::$configure instanceof Closure) {
            (self::$configure)($settings);
        }
    }
}
