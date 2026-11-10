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
use Simtabi\Laranail\Settings\Authorization\AccessGuard;

/**
 * Load one scope's bag into the cache ahead of the first read (design §7.5,
 * `laranail::settings.warm`): the global scope by default, or one owner's. One query per cached
 * database layer on a miss, none when the bag is already cached. Needs `manage_stores` when an
 * actor is present and is audited as a `manage_stores` entry. Returns how many records the bag
 * holds (0 when the store is not cached).
 *
 * The bag is the scope's whole set of rows, not only its `autoload` rows: a bag is answered as
 * complete, so a partial one would turn a non-autoload value into a miss.
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
