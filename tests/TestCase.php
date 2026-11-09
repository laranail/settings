<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests;

use Override;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Package\Tools\Testing\IsolatedTestCase;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

abstract class TestCase extends IsolatedTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The runtime layer writes through the `local` disk; keep every test's files apart.
        Storage::fake('local');
        // No shipped file unless a test ships one.
        config()->set('laranail.settings.layers.shipped.path', '/nonexistent/laranail-settings-defaults.php');
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [SettingsServiceProvider::class];
    }
}
