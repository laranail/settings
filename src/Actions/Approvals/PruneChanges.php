<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Data\ApprovalPolicy;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Expire overdue open change requests and delete decided ones older than `approvals.keep_days`,
 * on every connection. Needs `prune`.
 */
final readonly class PruneChanges
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
        private ApprovalPolicy $policy = new ApprovalPolicy,
    ) {}

    /** @return array{expired: int, deleted: int} */
    public function handle(?Actor $actor = null): array
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::Prune, $subject);

        $now = CarbonImmutable::now();
        $cutoff = $this->policy->keepDays === null ? null : $now->subDays($this->policy->keepDays);
        $totals = ['expired' => 0, 'deleted' => 0];
        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || isset($seen[$database->connectionName()])) {
                continue;
            }

            $seen[$database->connectionName()] = true;
            $connection = $database->connectionName();

            // Every tenant's requests: expire the overdue ones, then delete decided ones past the cutoff.
            $totals['expired'] += $database->attempt($store->name, static fn (): int => SettingChangeRequest::on($connection)
                ->allTenants()
                ->overdue($now)
                ->update(['status' => ChangeStatus::Expired->value, 'updated_at' => $now]));

            if ($cutoff instanceof CarbonImmutable) {
                $totals['deleted'] += $database->attempt($store->name, static fn (): int => SettingChangeRequest::on($connection)
                    ->allTenants()
                    ->whereNotIn('status', [ChangeStatus::Pending->value, ChangeStatus::Approved->value])
                    ->where('updated_at', '<', $cutoff)
                    ->toBase()->delete());
            }
        }

        $this->access->auditor()->record(AuditAction::Prune, AuditOutcome::Allowed, $subject, $actor, meta: ['target' => 'change_requests'] + $totals);

        return $totals;
    }
}
