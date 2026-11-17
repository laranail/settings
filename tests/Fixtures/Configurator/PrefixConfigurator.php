<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Simtabi\Laranail\Settings\Configurator\CacheSetup;
use Simtabi\Laranail\Settings\Configurator\StoreSetup;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\SettingsConfigurator;

final class PrefixConfigurator implements SettingsConfigurator
{
    public function configure(SettingsSetup $settings): void
    {
        $settings
            ->tables(prefix: 'acme_')
            ->store('teams', static fn (StoreSetup $store): StoreSetup => $store->layers('runtime')->owners('team')->inherits())
            ->cache(static fn (CacheSetup $cache): CacheSetup => $cache->ttl(600));
    }
}
