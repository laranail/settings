<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Events;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;

/**
 * An actor was refused an ability (or a call without an actor was refused because one is
 * required). Dispatched at once, not after commit: the refusal throws, so a transaction around
 * it usually rolls back, and a security listener must still hear of it. Never carries a value.
 *
 * `$refusal` is the audit's refusal code: `access_denied`, `approval_unavailable` or
 * `actor_required`; `$reason` the authorizer's explanation, when it gave one.
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
