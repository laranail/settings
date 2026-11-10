<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;
use Simtabi\Laranail\Settings\Services\Database\ChangeRequestRepository;

/**
 * Rename an owner alias in stored settings (G-30), after the application renamed it in its morph
 * map: every scope `<from>:<id>` becomes `<to>:<id>` in every database store (with `settable_type`
 * and per-row slugs), in the history and in change requests, in chunks (G-44). `$from` may be a
 * class name, which is how rows written before the alias allow-list look (G-6). The audit trail is
 * left as it was: it records what happened under the old name. Needs `manage_stores`; audited.
 * Update the stores' `owners` lists to the new alias as well.
 */
final readonly class RescopeOwners
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @return array{values: int, history: int, changes: int} */
    public function handle(string $from, string $to, ?Actor $actor = null): array
    {
        if ($from === '' || $from === Scope::GLOBAL || str_contains($from, ':')) {
            throw new InvalidIdentifier(sprintf('rescope: [%s] is not an owner alias.', $from));
        }

        $to = new OwnerRef($to, '0')->alias;

        if ($from === $to) {
            throw new InvalidIdentifier(sprintf('rescope: [%s] is already the alias.', $from));
        }

        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        $totals = ['values' => 0, 'history' => 0, 'changes' => 0];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || ! $database->isWritable()) {
                continue;
            }

            $store->transaction(function () use ($database, $store, $from, $to, &$totals): void {
                $totals['values'] += $database->rescope($store->name, $from, $to);

                if ($database->shape()->historyTable) {
                    $totals['history'] += new HistoryRepository($database)->rescope($store->name, $from, $to);
                }
            });

            $store->forgetCachedStore();
        }

        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if ($database instanceof DatabaseDriver && $database->shape()->changeRequestsTable && ! isset($seen[$database->connectionName()])) {
                $seen[$database->connectionName()] = true;
                $totals['changes'] += new ChangeRequestRepository($database)->rescope($store->name, $from, $to);
            }
        }

        $this->access->auditor()->record(AuditAction::Rescope, AuditOutcome::Allowed, $subject, $actor, meta: ['from' => $from, 'to' => $to] + $totals);

        return $totals;
    }
}
