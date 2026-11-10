<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authentication;

use Closure;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;

/** Resolves the actor with a closure set through `configuration()->resolveActorsUsing()`. */
final readonly class ClosureActorResolver implements ActorResolver
{
    /** @param Closure(?Request): ?Actor $callback */
    public function __construct(
        private Closure $callback,
    ) {}

    public function resolve(?Request $request = null): ?Actor
    {
        return ($this->callback)($request);
    }
}
