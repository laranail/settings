<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

/**
 * The readiness probe over laranail/db-tools' schema readiness: reachable, migrated, and every
 * named table present. db-tools memoises its reports in a singleton, so {@see flush()} is called
 * after migrations and at the start of each request and job.
 */
final readonly class SchemaReadinessProbe implements ReadinessProbe
{
    public function __construct(
        private SchemaReadinessInterface $readiness,
    ) {}

    public function isReady(?string $connection, array $tables): bool
    {
        return $this->readiness->isReady($tables, $connection);
    }

    public function flush(): void
    {
        $this->readiness->flush();
    }
}
