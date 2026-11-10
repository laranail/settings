<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** An approved change request was written as `$version`. */
final readonly class ChangeApplied implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $id,
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public string $operation,
        public int $version = 0,
    ) {}
}
