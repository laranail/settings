<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Authorization;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Contracts\Authorizer;

/**
 * Asks each authorizer in order; the first that does not abstain decides. When all abstain the
 * chain abstains, and `authorization.undefined` applies.
 */
final readonly class ChainAuthorizer implements Authorizer
{
    /** @param list<Authorizer> $authorizers */
    public function __construct(
        private array $authorizers,
    ) {}

    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        foreach ($this->authorizers as $authorizer) {
            $decision = $authorizer->authorize($actor, $ability, $subject);

            if (! $decision->isAbstention()) {
                return $decision;
            }
        }

        return Decision::abstain('no authorizer decided');
    }
}
