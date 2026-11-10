<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\HistoryFilter;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;

/** Build the value history query for the filtered store, or the default store. */
final readonly class QueryHistory
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @throws HistoryUnavailable */
    public function handle(HistoryFilter $filter, ?Actor $actor = null): Builder
    {
        $store = $filter->store instanceof StoreName ? $this->stores->get($filter->store) : $this->stores->default();

        $this->access->authorize($actor, Ability::ViewHistory, new Subject(
            $store->name,
            key: $filter->key,
            owner: $filter->scope?->owner(),
        ));

        $history = HistoryRepository::locate($this->stores, $store->name)
            ?? throw new HistoryUnavailable(sprintf('Store [%s] keeps no history: it has no active database layer, or its schema was recorded without the history table.', $store->name));

        return $history->query($filter->with(store: $store->name));
    }
}
