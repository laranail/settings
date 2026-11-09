<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One page of the audit trail, newest first. `nextCursor` / `previousCursor` are opaque strings
 * to pass back to `paginate()`; null at either end.
 */
final readonly class AuditPage
{
    /** @param list<AuditEntry> $entries */
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
