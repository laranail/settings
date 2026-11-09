<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;

/**
 * Turns the current request (or context) into an {@see Actor}. The host's own middleware does
 * the authenticating; a resolver only reads its result.
 */
interface ActorResolver
{
    /** The authenticated actor, or null when nobody is authenticated. */
    public function resolve(?Request $request = null): ?Actor;
}
