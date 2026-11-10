<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A setting was rolled back to the value it held at `$toVersion`, written as the new version
 * `$version` through the normal write pipeline. Dispatched after commit; identifiers only.
 */
final readonly class SettingRolledBack implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public int $toVersion,
        public int $version,
    ) {}
}
