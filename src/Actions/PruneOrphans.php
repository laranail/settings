<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Events\OrphansPruned;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/** Remove the settings of owners that no longer exist, as {@see FindOrphans} finds them. */
final readonly class PruneOrphans
{
    public function __construct(
        private StoreRegistry $stores,
        private FlushScope $flush,
        private FindOrphans $find,
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    /** Remove every orphan's settings; returns the number of rows removed. */
    public function handle(?Actor $actor = null): int
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);
        $removed = 0;
        $flushed = 0;

        foreach ($this->find->handle()['orphans'] as $name => $owners) {
            $store = $this->stores->all()[$name];

            foreach ($owners as $owner) {
                $removed += $this->flush->handle($store, $owner, $actor);
                $flushed++;
            }
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'orphans', 'owners' => $flushed, 'removed' => $removed]);
        $this->events->dispatch(new OrphansPruned($flushed, $removed));

        return $removed;
    }
}
