<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the API's controller afresh for every request. A route keeps the controller it built for
 * the first request, and under Octane that would keep the first request's scoped collaborators
 * (the actor's access guard, the tenant's stores) for every later one. Runs before the controller
 * is built, since the API's controllers declare no middleware of their own.
 */
final readonly class FreshControllers
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route instanceof Route) {
            $route->flushController();
        }

        /** @var Response */
        return $next($request);
    }
}
