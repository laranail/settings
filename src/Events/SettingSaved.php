<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Dispatched after the surrounding database transaction commits, if any. Carries identifiers
 * only, never the value, so a queued listener cannot leak a secret into the jobs table.
 */
final readonly class SettingSaved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public int $version,
    ) {}
}
