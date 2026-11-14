<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Verification;

use Closure;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Contracts\Verifier;

/** A verifier from a closure, registered with `configuration()->verifyUsing()`. */
final readonly class ClosureVerifier implements Verifier
{
    /** @param Closure(Actor, Subject): bool $callback */
    public function __construct(
        private Closure $callback,
    ) {}

    public function isVerified(Actor $actor, Subject $subject): bool
    {
        return ($this->callback)($actor, $subject) === true;
    }
}
