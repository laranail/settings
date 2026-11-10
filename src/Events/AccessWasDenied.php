<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/**
 * An actor was refused an ability. Dispatched at once rather than after commit, because the
 * refusal usually rolls the surrounding transaction back and a listener must still hear of it.
 *
 * `$refusal` is `access_denied`, `approval_unavailable` or `actor_required`.
 */
final readonly class AccessWasDenied
{
    public function __construct(
        public Ability $ability,
        public Subject $subject,
        public ?Actor $actor,
        public string $refusal,
        public ?string $reason = null,
    ) {}
}
