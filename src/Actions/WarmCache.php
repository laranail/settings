<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;

/**
 * Load one scope's bag into the cache ahead of the first read. Returns how many records the bag
 * holds, or 0 when the store is not cached.
 */
final readonly class WarmCache
{
    public function __construct(private AccessGuard $access) {}

    public function handle(Store $store, ?OwnerRef $owner = null, ?Actor $actor = null): int
    {
        $subject = new Subject($store->name, owner: $owner);
        $this->access->authorize($actor, Ability::ManageStores, $subject);

        if (! $store->isCached()) {
            return 0;
        }

        $records = $store->warmCache([Scope::of($owner)]);

        $this->access->auditor()->record(AuditAction::ManageStores, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'cache.warm', 'records' => $records]);

        return $records;
    }
}
