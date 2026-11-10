<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Configuration;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/** The valid persisted stores and layers (§16.11): needs `manage_stores`. */
final readonly class ListConfigurationEntries
{
    public function __construct(
        private StoreRegistry $stores,
        private PersistedConfiguration $persisted,
        private AccessGuard $access,
    ) {}

    /** @return list<ConfigurationEntry> */
    public function handle(?Actor $actor = null): array
    {
        $this->access->authorize($actor, Ability::ManageStores, new Subject($this->stores->default()->name));

        $entries = [];

        foreach ($this->persisted->layers() as $name => $config) {
            $entries[] = new ConfigurationEntry(ConfigurationEntry::LAYER, (string) $name, $config);
        }

        foreach ($this->persisted->stores() as $name => $config) {
            $entries[] = new ConfigurationEntry(ConfigurationEntry::STORE, (string) $name, $config);
        }

        return $entries;
    }
}
