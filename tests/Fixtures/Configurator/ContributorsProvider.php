<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Facades\Settings;

/** Registers each class a test lists, in order, as packages, modules and applications would. */
final class ContributorsProvider extends ServiceProvider
{
    /** @var list<class-string> */
    public static array $classes = [];

    public function register(): void
    {
        foreach (self::$classes as $class) {
            Settings::register($class);
        }
    }
}
