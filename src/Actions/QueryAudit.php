<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

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

        $this->access->authorize($actor, Ability::ViewAudit, new Subject(
            $store->name,
            key: $filter->key,
            owner: $filter->scope?->owner(),
        ));

        $database = $this->stores->auditDatabase($store->name)
            ?? throw new DriverUnavailable(sprintf('Store [%s] has no active database layer, so its audit trail went to the log and cannot be queried.', $store->name));

        return SettingAuditEntry::on($database->connectionName())->matching($filter);
    }
}
