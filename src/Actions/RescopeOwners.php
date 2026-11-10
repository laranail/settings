<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Subject;
use Illuminate\Database\Eloquent\Collection;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Models\SettingHistory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Rename an owner alias in stored values, history and change requests after the application
 * renamed it in its morph map. `$from` may be a class name. The audit trail is left unchanged.
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
                $totals['history'] += $database->attempt($store->name, fn (): int => $this->rescope(SettingHistory::on($database->connectionName())->allTenants()->where('store', $store->name->value), $from, $to));
            });

            $store->forgetCachedStore();
        }

        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if ($database instanceof DatabaseDriver && ! isset($seen[$database->connectionName()])) {
                $seen[$database->connectionName()] = true;
                $totals['changes'] += $database->attempt($store->name, fn (): int => $this->rescope(SettingChangeRequest::on($database->connectionName())->allTenants(), $from, $to));
            }
        }

        $this->access->auditor()->record(AuditAction::Rescope, AuditOutcome::Allowed, $subject, $actor, meta: ['from' => $from, 'to' => $to] + $totals);

        return $totals;
    }

    /**
     * Rename an owner alias in the scopes these rows hold, and return how many changed. Their
     * timestamps stay as they are.
     *
     * @param Builder<SettingHistory>|Builder<SettingChangeRequest> $rows
     */
    private function rescope(Builder $rows, string $from, string $to): int
    {
        $changed = 0;

        $rows->where('scope', '>=', $from . ':')
            ->where('scope', '<', $from . ';')
            ->chunkById(500, static function (Collection $models) use ($from, $to, &$changed): void {
                foreach ($models as $model) {
                    $scope = $model->getAttribute('scope');
                    $model->timestamps = false;
                    $model->update(['scope' => $to . substr(is_string($scope) ? $scope : '', strlen($from))]);
                    $changed++;
                }
            });

        return $changed;
    }
}
