<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Approvals;

use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Models\SettingChangeRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

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

        // An actor who may not approve sees only their own requests: filtered in the query, so a
        // page is never filled with other people's requests and then emptied.
        $own = $actor instanceof Actor && ! $actor->isSystem() && ! $this->access->allows($actor, Ability::Approve, $subject) ? $actor : null;
        $database = $store->changeRequestsDatabase();
        $requests = $database->attempt($store->name, static function () use ($database, $filter, $own, $after, $limit): array {
            $query = SettingChangeRequest::on($database->connectionName())->matching($filter);

            if ($own instanceof Actor) {
                SettingChangeRequest::requestedBy($query, $own);
            }

            return $query
                ->when($after !== null, static fn (Builder $query): Builder => $query->where('id', '<', $after))
                ->limit($limit)
                ->get()
                ->map(static fn (SettingChangeRequest $request): ChangeRequest => $request->toRequest())
                ->values()
                ->all();
        });

        return array_values(array_filter($requests, static fn (ChangeRequest $request): bool => ! $own instanceof Actor || $request->isRequestedBy($own)));
    }
}
