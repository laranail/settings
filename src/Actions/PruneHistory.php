<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Events\HistoryPruned;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;

/**
 * Apply the history retention policy (G-29) to the history table of every connection a store
 * uses: drop entries older than `history.keep_days`, and entries more than
 * `history.keep_versions` versions behind their setting's newest. Either rule may be off (null),
 * not both. Needs `prune` when an actor is present (checked against the default store); the prune
 * is audited, so the trail records who shortened the history. Returns the number removed.
 */
final readonly class PruneHistory
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    public function handle(?int $keepDays, ?int $keepVersions, ?Actor $actor = null): int
    {
        if ($keepDays === null && $keepVersions === null) {
            throw new InvalidConfiguration('laranail.settings.history: set keep_days, keep_versions or both before pruning.');
        }

        foreach (['keep_days' => $keepDays, 'keep_versions' => $keepVersions] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidConfiguration(sprintf('laranail.settings.history.%s: [%d] must be at least 1.', $name, $value));
            }
        }

        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);

        $cutoff = $keepDays === null ? null : CarbonImmutable::now()->subDays($keepDays);
        $removed = 0;
        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || ! $database->shape()->historyTable || isset($seen[$database->connectionName()])) {
                continue;
            }

            $seen[$database->connectionName()] = true;
            $removed += new HistoryRepository($database)->prune($cutoff, $keepVersions, $store->name);
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'history', 'keep_days' => $keepDays, 'keep_versions' => $keepVersions, 'removed' => $removed]);
        $this->events->dispatch(new HistoryPruned($removed, $keepDays, $keepVersions));

        return $removed;
    }
}
