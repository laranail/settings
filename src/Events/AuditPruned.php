<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Audit entries older than the retention period were deleted. */
final readonly class AuditPruned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $removed,
        public int $keepDays,
    ) {}
}
