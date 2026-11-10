<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\ChangeRequestRepository;

/**
 * List a store's change requests, newest first. An actor without `approve` sees only their own.
 */
final readonly class QueryChanges
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @return list<ChangeRequest> */
    public function handle(ChangeFilter $filter, ?Actor $actor = null, int $limit = 100, ?string $after = null): array
    {
        $store = $this->stores->get($filter->store);
        $subject = new Subject($store->name);

        if (! $actor instanceof Actor) {
            $this->access->assertActorNotRequired(Ability::Approve, $subject);
        }

        $requests = ChangeRequestRepository::of($store)->list($filter, $limit, $after);

        if (! $actor instanceof Actor || $actor->isSystem() || $this->access->allows($actor, Ability::Approve, $subject)) {
            return $requests;
        }

        return array_values(array_filter($requests, static fn (ChangeRequest $request): bool => $request->isRequestedBy($actor)));
    }
}
