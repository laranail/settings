<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * One scope's row was unlocked. Dispatched after the surrounding transaction commits;
 * identifiers only, never the value.
 */
final readonly class SettingUnlocked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public int $version,
    ) {}
}
