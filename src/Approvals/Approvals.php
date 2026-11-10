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
use Simtabi\Laranail\Settings\Exceptions\ChangeRequestClosed;
use Simtabi\Laranail\Settings\Exceptions\UnknownChangeRequest;
use Simtabi\Laranail\Settings\Actions\Approvals\ApprovalActions;

/**
 * The change requests of one store (§16.7), as the handle's actor: list them (`->pending()`,
 * `->status()`, `->key()`, `->for($owner)`, then `->get()`), and approve, reject or cancel one by
 * id. Immutable: every narrowing returns a new query.
 */
final readonly class Approvals
{
    public function __construct(
        private ApprovalActions $actions,
        private Store $store,
        private ?Actor $actor = null,
        private ?ChangeFilter $filter = null,
    ) {}

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
        return $this->actions->query->handle($this->filter(), $this->actor, $limit, $after);
    }

    /**
     * One request, if the actor may see it.
     *
     * @throws UnknownChangeRequest
     */
    public function find(string $id): ChangeRequest
    {
        return $this->actions->query->handle(new ChangeFilter($this->store->name, id: $id), $this->actor, 1)[0] ?? throw new UnknownChangeRequest(sprintf('Store [%s] has no change request [%s].', $this->store->name, $id));
    }

    /**
     * @throws AccessDenied
     * @throws ChangeRequestClosed
     */
    public function approve(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->actions->approve->handle(new ChangeDecision($this->store, $id, $reason), $this->actor);
    }

    /** @throws ChangeRequestClosed */
    public function reject(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->actions->reject->handle(new ChangeDecision($this->store, $id, $reason), $this->actor);
    }

    /** @throws ChangeRequestClosed */
    public function cancel(string $id, ?string $reason = null): ChangeRequest
    {
        return $this->actions->cancel->handle(new ChangeDecision($this->store, $id, $reason), $this->actor);
    }

    private function filter(): ChangeFilter
    {
        return $this->filter ?? new ChangeFilter($this->store->name);
    }

    private function with(ChangeFilter $filter): self
    {
        return new self($this->actions, $this->store, $this->actor, $filter);
    }
}
