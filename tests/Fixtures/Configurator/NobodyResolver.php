<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Fixtures\Configurator;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;

/** An actor resolver the container builds with no arguments; it finds nobody. */
final class NobodyResolver implements ActorResolver
{
    public function resolve(?Request $request = null): ?Actor
    {
        return null;
    }
}
