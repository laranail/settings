<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The current time, through Laravel's date seam, so `$this->travelTo()` and `Date::use()` reach
 * every timestamp the package writes. The one place the package reads the clock.
 */
final readonly class Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now());
    }
}
