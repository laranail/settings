<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Configuration;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/** Save a persisted store or layer, validated like the configuration file. */
final readonly class SaveConfigurationEntry
{
    public function __construct(
        private StoreRegistry $stores,
        private PersistedConfiguration $persisted,
        private AccessGuard $access,
    ) {}

    /** @throws InvalidConfiguration */
    public function handle(ConfigurationEntry $entry, ?Actor $actor = null): void
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        $entry->kind === ConfigurationEntry::STORE
            ? $this->persisted->saveStore($entry->name, $entry->config)
            : $this->persisted->saveLayer($entry->name, $entry->config);

        $this->access->auditor()->record(AuditAction::ManageStores, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'save', 'kind' => $entry->kind, 'name' => $entry->name]);
    }
}
