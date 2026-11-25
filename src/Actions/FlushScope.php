<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Events\ScopeFlushed;
use Simtabi\Laranail\Settings\Events\ScopeFlushing;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;

/** Remove every value an owner holds in a store; returns the number of rows removed. */
final readonly class FlushScope
{
    public function __construct(
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    public function handle(Store $store, OwnerRef $owner, ?Actor $actor = null): int
    {
        $scope = $owner->scope();
        $subject = new Subject($store->name, owner: $owner);

        $this->access->authorize($actor, Ability::Forget, $subject);

        return $store->transaction(function () use ($store, $scope, $subject, $actor): int {
            $this->events->dispatch(new ScopeFlushing($store->name->value, $scope->value));
            $removed = 0;

            foreach ($store->layers as $layer) {
                if (! $layer->driver->isWritable()) {
                    continue;
                }

                $removed += $layer->driver instanceof DatabaseBacked
                    ? $layer->driver->database()->flushScope($store->name, $scope)
                    : $this->forgetEach($store, $layer->driver, $scope);
            }

            // The delete bypassed the cache, so drop the scope's bag after commit.
            $store->forgetCached($scope);

            $this->access->auditor()->record(AuditAction::Forget, AuditOutcome::Allowed, $subject, $actor, meta: ['flushed' => $removed]);
            $this->events->dispatch(new ScopeFlushed($store->name->value, $scope->value, $removed));

            return $removed;
        });
    }

    private function forgetEach(Store $store, Driver $driver, Scope $scope): int
    {
        $records = $driver->load($store->name, [$scope])->all();

        foreach ($records as $record) {
            $driver->forget($store->name, $record->scope, $record->key, $record->locale);
        }

        return count($records);
    }
}
