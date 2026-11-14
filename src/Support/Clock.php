<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The current time through Laravel's date seam, so `travelTo()` and `Date::use()` apply.
 */
final readonly class Clock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now());
    }
}
