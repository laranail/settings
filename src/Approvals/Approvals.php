<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Approvals;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\ChangeFilter;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Enums\ChangeStatus;
use Simtabi\Laranail\Settings\Data\ChangeDecision;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Actions\Approvals\CancelChange;
use Simtabi\Laranail\Settings\Actions\Approvals\QueryChanges;
use Simtabi\Laranail\Settings\Actions\Approvals\RejectChange;
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Actions\Approvals\ApproveChange;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;

/**
 * The change requests of one store, seen as the handle's actor. Narrow with `pending()`,
 * `status()`, `key()` or `for($owner)`, then `get()`; approve, reject or cancel one by id.
 * Every narrowing returns a new instance.
 */
final readonly class Approvals
{
    /** The store and actor are set by {@see in()}; without them, the default store as trusted code. */
    public function __construct(
        private QueryChanges $query,
        private ApproveChange $approve,
        private RejectChange $reject,
        private CancelChange $cancel,
        private StoreRegistry $stores,
        private ?Store $store = null,
        private ?Actor $actor = null,
        private ?ChangeFilter $filter = null,
    ) {}

    /** These change requests: the store's, as this actor. */
    public function in(Store $store, ?Actor $actor = null): self
    {
        return new self($this->query, $this->approve, $this->reject, $this->cancel, $this->stores, $store, $actor);
    }

    /** Open requests only (pending, or approved and waiting for more approvals). */
    public function pending(): self
    {
        return $this->with($this->filter()->with(status: ChangeStatus::Pending));
    }

    public function status(ChangeStatus $status): self
    {
        return $this->with($this->filter()->with(status: $status));
    }

    public function key(string $key): self
    {
        return $this->with($this->filter()->with(key: new Key($key)));
    }

    public function for(Settable|OwnerRef $owner): self
    {
        return $this->with($this->filter()->with(scope: Scope::of($owner instanceof Settable ? $owner->settingsOwner() : $owner)));
    }

    /**
     * Newest first. An actor without `approve` sees only their own requests.
     *
     * @return list<ChangeRequest>
     */
    public function get(int $limit = 100, ?string $after = null): array
    {
        return $this->query->handle($this->filter(), $this->actor, $limit, $after);
    }

    /**
     * One request, if the actor may see it.
     *
     * @throws UnknownChangeRequest
     */
    public function find(string $id): ChangeRequest
    {
        return $this->query->handle(new ChangeFilter($this->store()->name, id: $id), $this->actor, 1)[0] ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $this->store()->name, $id));
    }

    /**
     * @throws AccessDenied
     * @throws ChangeRequestClosed
     */
    public function approve(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->approve->handle(new ChangeDecision($this->store(), $id, $reason), $this->actor);
    }

    /** @throws ChangeRequestClosed */
    public function reject(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->reject->handle(new ChangeDecision($this->store(), $id, $reason), $this->actor);
    }

    /** @throws ChangeRequestClosed */
    public function cancel(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->cancel->handle(new ChangeDecision($this->store(), $id, $reason), $this->actor);
    }

    private function filter(): ChangeFilter
    {
        return $this->filter ?? new ChangeFilter($this->store()->name);
    }

    private function store(): Store
    {
        return $this->store ?? $this->stores->default();
    }

    private function with(ChangeFilter $filter): self
    {
        return new self($this->query, $this->approve, $this->reject, $this->cancel, $this->stores, $this->store, $this->actor, $filter);
    }
}
