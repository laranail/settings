<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Audit;

use Closure;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Models\SettingAuditEntry;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

/**
 * Writes audit entries to the audit table on the connection of the entry's store (or the default
 * store). The write and its audit row share a transaction, so a failed insert rolls the write back.
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
        return $this->target($entry) instanceof DatabaseDriver;
    }

    public function record(AuditEntry $entry): void
    {
        $database = $this->target($entry) ?? throw new DriverFailure(sprintf('The database audit sink has no active database layer for store [%s].', $entry->store ?? 'default'));

        $database->attempt(new StoreName($entry->store ?? 'audit'), static fn (): SettingAuditEntry => SettingAuditEntry::on($database->connectionName())
            ->create(SettingAuditEntry::attributesOf($entry)));
    }

    private function target(AuditEntry $entry): ?DatabaseDriver
    {
        return ($this->stores)()->auditDatabase($entry->store === null ? null : new StoreName($entry->store));
    }
}
