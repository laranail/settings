<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\AuditPage;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;

/**
 * `$settings->audit()`: an immutable query over the audit trail. Each condition returns a new
 * query; `get()`, `cursor()` and `paginate()` run it through {@see QueryAudit}, which checks
 * `view_audit` for the handle's actor. Entries hold actions and identifiers, never values.
 *
 *     $settings->as($admin)->audit()->for($user)->key('mail.from_address')
 *         ->action(AuditAction::Update)->since(now()->subWeek())->get();
 */
final readonly class AuditQuery
{
    public function __construct(
        private QueryAudit $action,
        private ?Actor $actor = null,
        private AuditFilter $filter = new AuditFilter,
    ) {}

    /** Entries about one owner (its scope); the store is not narrowed by this. */
    public function for(Settable|OwnerRef $owner): self
    {
        $ref = $owner instanceof Settable ? $owner->settingsOwner() : $owner;

        return $this->with($this->filter->with(scope: $ref->scope()));
    }

    /** Entries about global values only. */
    public function global(): self
    {
        return $this->with($this->filter->with(scope: Scope::global()));
    }

    public function store(string $name): self
    {
        return $this->with($this->filter->with(store: new StoreName($name)));
    }

    public function key(string $key): self
    {
        return $this->with($this->filter->with(key: new Key($key)));
    }

    public function action(AuditAction $action): self
    {
        return $this->with($this->filter->with(action: $action));
    }

    public function outcome(AuditOutcome $outcome): self
    {
        return $this->with($this->filter->with(outcome: $outcome));
    }

    public function since(DateTimeInterface $moment): self
    {
        return $this->with($this->filter->with(since: CarbonImmutable::instance($moment)));
    }

    public function until(DateTimeInterface $moment): self
    {
        return $this->with($this->filter->with(until: CarbonImmutable::instance($moment)));
    }

    public function filter(): AuditFilter
    {
        return $this->filter;
    }

    /** @return list<AuditEntry> newest first */
    public function get(?int $limit = null): array
    {
        return $this->action->get($this->filter, $this->actor, $limit);
    }

    /** @return LazyCollection<int, AuditEntry> */
    public function cursor(): LazyCollection
    {
        return $this->action->cursor($this->filter, $this->actor);
    }

    /** One page; pass its `nextCursor` back for the next. */
    public function paginate(int $perPage = 50, ?string $cursor = null): AuditPage
    {
        return $this->action->paginate($this->filter, $this->actor, $perPage, $cursor);
    }

    private function with(AuditFilter $filter): self
    {
        return new self($this->action, $this->actor, $filter);
    }
}
