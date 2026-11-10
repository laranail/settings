<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/**
 * An actor holding an ability was refused because it needs step-up verification and the actor
 * has not verified recently. Dispatched at once, like AccessWasDenied. Never carries a value.
 */
final readonly class VerificationWasRequired
{
    public function __construct(
        public Ability $ability,
        public Subject $subject,
        public Actor $actor,
    ) {}
}
