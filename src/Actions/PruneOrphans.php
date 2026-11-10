<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;

/**
 * Remove the settings of owners that no longer exist (G-7), as {@see FindOrphans} finds them.
 *
 * Removing needs `prune` when an actor is present (and, per owner, the `forget` that flushing
 * needs); the run is audited as one `prune` entry besides the per-owner `forget` entries.
 */
final readonly class PruneOrphans
{
    private AccessGuard $access;

    /** @param Closure(string): ?string $models the model class of a morph alias (the morph map) */
    public function __construct(
        private StoreRegistry $stores,
        private FlushScope $flush,
        private Closure $models,
        ?AccessGuard $access = null,
    ) {
        $this->access = $access ?? AccessGuard::closed();
    }

    /**
     * The orphaned owners per store, and the aliases no model answers for.
     *
     * @deprecated 0.1 Use {@see FindOrphans::handle()}; this forwards to it and is removed in 1.0.
     *
     * @return array{orphans: array<string, list<OwnerRef>>, unmapped: list<string>}
     */
    public function find(): array
    {
        return $this->finder()->handle();
    }

    /**
     * Remove every orphan's settings; returns the number of rows removed.
     */
    public function handle(?Actor $actor = null): int
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);
        $removed = 0;
        $flushed = 0;

        foreach ($this->finder()->handle()['orphans'] as $name => $owners) {
            $store = $this->stores->all()[$name];

            foreach ($owners as $owner) {
                $removed += $this->flush->handle($store, $owner, $actor);
                $flushed++;
            }
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'orphans', 'owners' => $flushed, 'removed' => $removed]);

        return $removed;
    }

    private function finder(): FindOrphans
    {
        return new FindOrphans($this->stores, $this->models);
    }
}
