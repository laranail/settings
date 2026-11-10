<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Pagination\Cursor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\AuditPage;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\DriverUnavailable;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\AuditRepository;

/**
 * Read the audit trail (§16.8): needs `view_audit` when an actor is present (a trusted call
 * without one reads freely unless an actor is required). Entries come from the audit table on
 * the connection of the filtered store (the default store when none is named), newest first.
 * Entries never carry a value, so there is nothing to redact.
 */
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

        return array_values(array_map(fn (object $row): AuditEntry => $this->entry((array) $row), $query->get()->all()));
    }

    /** @return LazyCollection<int, AuditEntry> streamed in chunks, for large trails */
    public function cursor(AuditFilter $filter, ?Actor $actor = null): LazyCollection
    {
        return $this->query($filter, $actor)->cursor()->map(fn (object $row): AuditEntry => $this->entry((array) $row));
    }

    /** One page, newest first; pass the page's `nextCursor` back for the next one. */
    public function paginate(AuditFilter $filter, ?Actor $actor = null, int $perPage = 50, ?string $cursor = null): AuditPage
    {
        $page = $this->query($filter, $actor)->cursorPaginate(max(1, $perPage), ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        return new AuditPage(
            array_values(array_map(fn (mixed $row): AuditEntry => $this->entry(is_object($row) ? (array) $row : []), $page->items())),
            max(1, $perPage),
            $page->nextCursor()?->encode(),
            $page->previousCursor()?->encode(),
        );
    }

    private function query(AuditFilter $filter, ?Actor $actor): Builder
    {
        $store = $filter->store instanceof StoreName ? $this->stores->get($filter->store) : $this->stores->default();

        $this->access->authorize($actor, Ability::ViewAudit, new Subject(
            $store->name,
            key: $filter->key,
            owner: $filter->scope?->owner(),
        ));

        $audit = AuditRepository::locate($this->stores, $store->name)
            ?? throw new DriverUnavailable(sprintf('Store [%s] has no active database layer, so its audit trail went to the log and cannot be queried.', $store->name));

        return $audit->query($filter);
    }

    /** @param array<array-key, mixed> $row */
    private function entry(array $row): AuditEntry
    {
        return AuditRepository::entry($row);
    }
}
