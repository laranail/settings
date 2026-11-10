<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Closure;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\AuditRepository;

/**
 * Writes each audit entry as a row of `settings_audit` on the connection of the entry's store
 * (or of the default store, for entries without one), so an allowed write and its audit row
 * share one transaction: if the audit insert fails, the write rolls back. The row has no value
 * column; entries never carry one.
 */
final readonly class DatabaseAuditSink implements AuditSink
{
    /** @param Closure(): StoreRegistry $stores resolved per entry, so runtime stores are seen */
    public function __construct(
        private Closure $stores,
    ) {}

    /** Whether there is an active database layer to write this entry to. */
    public function accepts(AuditEntry $entry): bool
    {
        return $this->target($entry) instanceof AuditRepository;
    }

    public function record(AuditEntry $entry): void
    {
        ($this->target($entry) ?? throw new DriverFailure(sprintf('The database audit sink has no active database layer for store [%s].', $entry->store ?? 'default')))
            ->insert($entry);
    }

    private function target(AuditEntry $entry): ?AuditRepository
    {
        return AuditRepository::locate(($this->stores)(), $entry->store === null ? null : new StoreName($entry->store));
    }
}
