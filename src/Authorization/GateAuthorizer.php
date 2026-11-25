<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authorization;

use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Auth\Access\Gate;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Contracts\Authorizer;

/**
 * Asks Laravel's gate for `laranail-settings.<ability>` with the {@see Subject}. An ability
 * with no gate defined abstains.
 */
final readonly class GateAuthorizer implements Authorizer
{
    public function __construct(
        private Gate $gate,
    ) {}

    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        $name = $ability->gateName();
        $response = $this->gate->forUser($actor->user)->inspect($name, [$subject]);

        if ($response->allowed()) {
            return Decision::allow();
        }

        if (! $this->gate->has($name)) {
            return Decision::abstain(sprintf('gate [%s] is not defined', $name));
        }

        return Decision::deny($response->message() ?? sprintf('gate [%s] denied', $name));
    }
}
