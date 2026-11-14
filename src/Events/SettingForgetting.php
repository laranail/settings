<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/** A setting is about to be forgotten. */
final readonly class SettingForgetting implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public int $version,
    ) {}
}
