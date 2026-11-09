<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;

/**
 * Keeps entries in memory for the current process: used by `Settings::fake()` and by tests that
 * assert on the audit trail.
 */
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
