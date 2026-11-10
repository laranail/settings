<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\History;

use Closure;
use DateTimeInterface;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\LazyCollection;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\HistoryPage;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Data\HistoryFilter;
use Simtabi\Laranail\Settings\Actions\QueryHistory;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;

/**
 * An immutable query over the value history, newest version first, returned by
 * `$settings->history()`. Payloads are as stored, so encrypted values stay encrypted, and they
 * are removed for an actor who may not view the setting.
 */
final readonly class HistoryQuery
{
    /** @param (Closure(HistoryEntry): bool)|null $mayView whether this actor may see an entry's values; null: every entry */
    public function __construct(
        private QueryHistory $action,
        private ?Actor $actor = null,
        private HistoryFilter $filter = new HistoryFilter,
        private ?Closure $mayView = null,
    ) {}

    /** Entries about one owner (its scope). */
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

    public function locale(string $locale): self
    {
        return $this->with($this->filter->with(locale: new Locale($locale)));
    }

    public function since(DateTimeInterface $moment): self
    {
        return $this->with($this->filter->with(since: CarbonImmutable::instance($moment)));
    }

    public function until(DateTimeInterface $moment): self
    {
        return $this->with($this->filter->with(until: CarbonImmutable::instance($moment)));
    }

    /** Versions in this range, inclusive; either end may be open (null). */
    public function versions(?int $from = null, ?int $to = null): self
    {
        return $this->with($this->filter->with(fromVersion: $from, toVersion: $to));
    }

    public function filter(): HistoryFilter
    {
        return $this->filter;
    }

    /** @return list<HistoryEntry> newest version first */
    public function get(?int $limit = null): array
    {
        $query = $this->action->handle($this->filter, $this->actor);

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return array_values(array_map(fn (object $row): HistoryEntry => $this->entry((array) $row), $query->get()->all()));
    }

    /** @return LazyCollection<int, HistoryEntry> streamed in chunks, for long trails */
    public function cursor(): LazyCollection
    {
        return $this->action->handle($this->filter, $this->actor)->cursor()->map(fn (object $row): HistoryEntry => $this->entry((array) $row));
    }

    /** One page; pass its `nextCursor` back for the next. */
    public function paginate(int $perPage = 50, ?string $cursor = null): HistoryPage
    {
        $page = $this->action->handle($this->filter, $this->actor)->cursorPaginate(max(1, $perPage), ['*'], 'cursor', $cursor === null ? null : Cursor::fromEncoded($cursor));

        return new HistoryPage(
            array_values(array_map(fn (mixed $row): HistoryEntry => $this->entry(is_object($row) ? (array) $row : []), $page->items())),
            max(1, $perPage),
            $page->nextCursor()?->encode(),
            $page->previousCursor()?->encode(),
        );
    }

    /** @param array<array-key, mixed> $row */
    private function entry(array $row): HistoryEntry
    {
        $entry = HistoryRepository::entry($row);

        return $this->mayView instanceof Closure && ! ($this->mayView)($entry) ? $entry->redacted() : $entry;
    }

    private function with(HistoryFilter $filter): self
    {
        return new self($this->action, $this->actor, $filter, $this->mayView);
    }
}
