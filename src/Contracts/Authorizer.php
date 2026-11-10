<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;

/** Decides abilities for a handle with a non-system actor. */
interface Authorizer
{
    /** Allow, deny, require verification or approval, or abstain for the next authorizer. */
    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision;
}
