<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;

/**
 * Decides whether an actor has recently proven their identity (step-up verification).
 */
interface Verifier
{
    public function isVerified(Actor $actor, Subject $subject): bool;
}
