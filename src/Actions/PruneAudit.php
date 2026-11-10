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
use Simtabi\Laranail\Settings\Events\AuditPruned;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingAuditEntry;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/** Delete audit rows older than `audit.keep_days` on every connection; returns the number removed. */
final readonly class PruneAudit
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

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

            // Every tenant's rows.
            $removed += $database->attempt($store->name, static fn (): int => SettingAuditEntry::on($database->connectionName())
                ->allTenants()
                ->where('occurred_at', '<', $cutoff)
                ->toBase()->delete());
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'audit', 'keep_days' => $keepDays, 'removed' => $removed]);
        $this->events->dispatch(new AuditPruned($removed, $keepDays));

        return $removed;
    }
}
