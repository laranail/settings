<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests;

use Override;
use Simtabi\Laranail\Package\Tools\Testing\IsolatedTestCase;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

abstract class TestCase extends IsolatedTestCase
{
    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }
}
