<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/** An actor was refused because the ability needs step-up verification. Dispatched at once. */
final readonly class VerificationWasRequired
{
    public function __construct(
        public Ability $ability,
        public Subject $subject,
        public Actor $actor,
    ) {}
}
