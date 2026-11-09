<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

/**
 * Whether a database layer can be used: the connection answers and its tables exist. Never
 * throws. Consulted only while bootstrap evidence holds, and to tell an outage
 * (`DriverUnavailable`) from any other database error (`DriverFailure`).
 */
interface ReadinessProbe
{
    /** @param list<string> $tables */
    public function isReady(?string $connection, array $tables): bool;

    /** Forget memoised answers, so the next call looks again (after migrations, per request). */
    public function flush(): void;
}
