<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Reads;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/** List the configured stores the actor may view. */
final readonly class ListStores
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @return list<Store> */
    public function handle(?Actor $actor = null): array
    {
        $stores = array_values($this->stores->all());

        if (! $actor instanceof Actor) {
            $this->access->assertActorNotRequired(Ability::View, new Subject($this->stores->default()->name));

            return $stores;
        }

        return array_values(array_filter($stores, fn (Store $store): bool => $actor->isSystem() || $this->access->allows($actor, Ability::View, new Subject($store->name))));
    }
}
