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
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Resolves through Sanctum and copies the token's abilities onto the actor. A token's
 * `settings:<ability>` limits what the authorizer may allow; it never grants anything itself.
 * A first-party session has no token and is not limited. Requires laravel/sanctum.
 */
final readonly class SanctumActorResolver implements ActorResolver
{
    /**
     * @param list<string> $guards
     * @param Closure(): ?Request $request the current request, or null outside HTTP
     * @param string $marker the class whose presence proves Sanctum is installed
     */
    public function __construct(
        private AuthFactory $auth,
        private array $guards,
        private Closure $request,
        string $marker = 'Laravel\Sanctum\Sanctum',
    ) {
        if (! class_exists($marker)) {
            throw new InvalidConfiguration('laranail.settings.authentication.resolver: [sanctum] needs laravel/sanctum; install it or choose another resolver.');
        }
    }

    public function resolve(?Request $request = null): ?Actor
    {
        $request ??= ($this->request)();

        foreach ($this->guards === [] ? ['sanctum'] : $this->guards as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user instanceof Authenticatable) {
                return Actor::user(
                    $user,
                    abilities: $this->abilitiesOf($user),
                    context: $request instanceof Request ? ActorContext::fromRequest($request) : new ActorContext,
                );
            }
        }

        return null;
    }

    /** @return list<string>|null the token's abilities, or null for a session without a token */
    private function abilitiesOf(Authenticatable $user): ?array
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if (! is_object($token) || ! isset($token->abilities) || ! is_array($token->abilities)) {
            return null;
        }

        return array_values(array_filter($token->abilities, is_string(...)));
    }
}
