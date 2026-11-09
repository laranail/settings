<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Verification;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Contracts\Verifier;

/**
 * Treats every actor as verified: step-up verification off (`verification.driver = none`).
 */
final readonly class NullVerifier implements Verifier
{
    public function isVerified(Actor $actor, Subject $subject): bool
    {
        return true;
    }
}
