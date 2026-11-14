<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authorization;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Contracts\Authorizer;

/** Authorizes with a closure that returns a Decision, true, false, or null to abstain. */
final readonly class ClosureAuthorizer implements Authorizer
{
    /** @param Closure(Actor, Ability, Subject): (Decision|bool|null) $callback */
    public function __construct(
        private Closure $callback,
    ) {}

    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        $decision = ($this->callback)($actor, $ability, $subject);

        return match (true) {
            $decision instanceof Decision => $decision,
            $decision === true            => Decision::allow(),
            $decision === false           => Decision::deny('the authorization callback refused'),
            default                       => Decision::abstain('the authorization callback had no opinion'),
        };
    }
}
