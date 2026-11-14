<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** History entries were deleted by retention; a null rule was not applied. */
final readonly class HistoryPruned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $removed,
        public ?int $keepDays,
        public ?int $keepVersions,
    ) {}
}
