<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** Every value of one scope in a store is about to be removed. */
final readonly class ScopeFlushing implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
    ) {}
}
