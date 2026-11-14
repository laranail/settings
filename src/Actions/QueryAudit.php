<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\LazyCollection;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\AuditPage;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Models\SettingAuditEntry;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/** Read the audit trail of the filtered store, or the default store, newest first. */
final readonly class QueryAudit
{
    public function __construct(
        private StoreRegistry $stores,
        private AccessGuard $access,
    ) {}

    /** @return list<AuditEntry> */
    public function get(AuditFilter $filter, ?Actor $actor = null, ?int $limit = null): array
    {
        $query = $this->query($filter, $actor);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return array_values(array_map(static fn (SettingAuditEntry $row): AuditEntry => $row->toEntry(), $query->get()->all()));
    }

    /** @return LazyCollection<int, AuditEntry> streamed in chunks, for large trails */
    public function cursor(AuditFilter $filter, ?Actor $actor = null): LazyCollection
    {
        return $this->query($filter, $actor)->cursor()->map(static fn (SettingAuditEntry $row): AuditEntry => $row->toEntry());
    }

    /** One page; pass its `nextCursor` back for the next. */
    public function paginate(AuditFilter $filter, ?Actor $actor = null, int $perPage = 50, ?string $cursor = null): AuditPage
    {
        $page = $this->query($filter, $actor)->cursorPaginate(max(1, $perPage), ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        return new AuditPage(
            array_values(array_map(static fn (SettingAuditEntry $row): AuditEntry => $row->toEntry(), $page->items())),
            max(1, $perPage),
            $page->nextCursor()?->encode(),
            $page->previousCursor()?->encode(),
        );
    }

    /** @return Builder<SettingAuditEntry> */
    private function query(AuditFilter $filter, ?Actor $actor): Builder
    {
        $store = $filter->store instanceof StoreName ? $this->stores->get($filter->store) : $this->stores->default();
        $subject = static fn (StoreName $name): Subject => new Subject($name, key: $filter->key, owner: $filter->scope?->owner());
        $readable = $filter->store instanceof StoreName ? null : $this->readableStores($actor, $subject);

        // No store filter: only the stores this actor may audit. None at all is refused, audited,
        // the same as a query naming one store.
        if ($readable === null || $readable === []) {
            $this->access->authorize($actor, Ability::ViewAudit, $subject($store->name));
        }

        $database = $this->stores->auditDatabase($store->name)
            ?? throw new DriverUnavailable(sprintf('Store [%s] has no active database layer, so its audit trail went to the log and cannot be queried.', $store->name));

        $query = SettingAuditEntry::on($database->connectionName())->matching($filter);

        return $readable === null ? $query : $query->whereIn('store', $readable);
    }

    /**
     * The names of the stores an actor may audit; null for trusted code and the system, which
     * read every store.
     *
     * @param Closure(StoreName): Subject $subject
     *
     * @return list<string>|null
     */
    private function readableStores(?Actor $actor, Closure $subject): ?array
    {
        if (! $actor instanceof Actor || $actor->isSystem()) {
            return null;
        }

        $readable = [];

        foreach ($this->stores->all() as $store) {
            if ($this->access->allows($actor, Ability::ViewAudit, $subject($store->name))) {
                $readable[] = $store->name->value;
            }
        }

        return $readable;
    }
}
