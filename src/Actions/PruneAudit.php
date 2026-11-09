<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Delete audit rows older than `audit.keep_days` from the audit table of every connection a
 * store uses. Returns the number of rows removed.
 */
final readonly class PruneAudit
{
    public function __construct(
        private StoreRegistry $stores,
    ) {}

    public function handle(int $keepDays): int
    {
        if ($keepDays < 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.audit.keep_days: [%d] must be at least 1.', $keepDays));
        }

        $cutoff = CarbonImmutable::now()->subDays($keepDays);
        $removed = 0;
        $seen = [];

        foreach ($this->stores->all() as $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || isset($seen[$database->connectionName()])) {
                continue;
            }

            $seen[$database->connectionName()] = true;

            $removed += $database->attempt($store->name, static fn (): int => $database->connection()
                ->table($database->names()->audit())
                ->where('occurred_at', '<', $cutoff)
                ->delete());
        }

        return $removed;
    }
}
