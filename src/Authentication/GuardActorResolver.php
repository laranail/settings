<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authentication;

use Closure;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Data\ActorContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;

/**
 * Resolves the user of the first guard in `authentication.guards` that has one, or of the
 * default guard when the list is empty.
 */
final readonly class GuardActorResolver implements ActorResolver
{
    /**
     * @param list<string> $guards
     * @param Closure(): ?Request $request the current request, or null outside HTTP
     */
    public function __construct(
        private AuthFactory $auth,
        private array $guards,
        private Closure $request,
    ) {}

    public function resolve(?Request $request = null): ?Actor
    {
        $request ??= ($this->request)();

        foreach ($this->guards === [] ? [null] : $this->guards as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Authenticatable) {
                return Actor::user($user, context: $request instanceof Request ? ActorContext::fromRequest($request) : new ActorContext);
            }
        }

        return null;
    }
}
