<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Code and config definitions were copied into the definitions table. */
final readonly class DefinitionsSynced implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $synced,
        public int $removed,
        public int $conflicts,
        public int $skipped,
    ) {}
}
