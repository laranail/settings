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
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/** Remove a persisted store or layer; returns whether one existed. */
final readonly class DeleteConfigurationEntry
{
    public function __construct(
        private StoreRegistry $stores,
        private PersistedConfiguration $persisted,
        private AccessGuard $access,
    ) {}

    public function handle(ConfigurationEntry $entry, ?Actor $actor = null): bool
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        $deleted = $this->persisted->delete($entry->kind, $entry->name);
        $this->access->auditor()->record(AuditAction::ManageStores, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'delete', 'kind' => $entry->kind, 'name' => $entry->name, 'existed' => $deleted]);

        return $deleted;
    }
}
