<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** One page of history, newest version first, with opaque cursors for `paginate()`. */
final readonly class HistoryPage
{
    /** @param list<HistoryEntry> $entries */
    public function __construct(
        public array $entries,
        public int $perPage,
        public ?string $nextCursor = null,
        public ?string $previousCursor = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
