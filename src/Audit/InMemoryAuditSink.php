<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/** Keeps audit entries in memory, for `Settings::fake()` and tests. */
final class InMemoryAuditSink implements AuditSink
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function record(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<AuditEntry> */
    public function entries(): array
    {
        return $this->entries;
    }
}
