<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Delete audit rows older than `audit.keep_days` from the audit table of every connection a
 * store uses. Returns the number of rows removed. With an actor it needs `prune` (checked
 * against the default store); the prune itself is audited, so the trail records who shortened
 * it.
 */
final readonly class PruneAudit
{
    private AccessGuard $access;

    public function __construct(
        private StoreRegistry $stores,
        ?AccessGuard $access = null,
    ) {
        $this->access = $access ?? AccessGuard::closed();
    }

    public function handle(int $keepDays, ?Actor $actor = null): int
    {
        if ($keepDays < 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.audit.keep_days: [%d] must be at least 1.', $keepDays));
        }

        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);

        $cutoff = CarbonImmutable::now()->subDays($keepDays);
        $removed = 0;
        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || isset($seen[$database->connectionName()])) {
                continue;
            }

            $seen[$database->connectionName()] = true;

            $removed += $database->attempt($store->name, static fn (): int => $database->connection()
                ->table($database->names()->audit())
                ->where('occurred_at', '<', $cutoff)
                ->delete());
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'audit', 'keep_days' => $keepDays, 'removed' => $removed]);

        return $removed;
    }
}
