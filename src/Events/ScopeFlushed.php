<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Every value of one scope in a store was removed.
 */
final readonly class ScopeFlushed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
        public int $removed,
    ) {}
}
