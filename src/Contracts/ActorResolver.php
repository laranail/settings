<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;

/** Turns the current request into an {@see Actor}; authentication itself is the host's. */
interface ActorResolver
{
    /** The authenticated actor, or null when nobody is authenticated. */
    public function resolve(?Request $request = null): ?Actor;
}
