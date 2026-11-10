<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The settings of owners that no longer exist were removed: how many owners, how many rows.
 */
final readonly class OrphansPruned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $owners,
        public int $rows,
    ) {}
}
