<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;

/**
 * The single authorization seam. Called only for a handle that carries a non-system actor;
 * trusted code without an actor never reaches it.
 */
interface Authorizer
{
    /** Allow, deny, require verification or approval, or abstain to let the next authorizer decide. */
    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision;
}
