<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Verification;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Contracts\Verifier;

/**
 * Laravel's own password confirmation: the session's `auth.password_confirmed_at` within
 * `auth.password_timeout`, the same check as the `password.confirm` middleware. Outside HTTP,
 * or when the session has no confirmation, it reads {@see Actor::$verifiedAt} with the same
 * timeout.
 */
final readonly class PasswordConfirmationVerifier implements Verifier
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    /**
     * @param Closure(): ?Session $session the current session, or null outside HTTP; a closure
     *                                     because the request changes under this scoped service
     */
    public function __construct(
        private Closure $session,
        private int $timeout,
        private Clock $clock,
    ) {}

    public function isVerified(Actor $actor, Subject $subject): bool
    {
        $now = $this->clock->now();
        $confirmedAt = ($this->session)()?->get(self::SESSION_KEY);

        if (is_int($confirmedAt) || (is_string($confirmedAt) && ctype_digit($confirmedAt))) {
            if (ActorTimestampVerifier::fresh(CarbonImmutable::createFromTimestamp((int) $confirmedAt), $now, $this->timeout)) {
                return true;
            }
        }

        return ActorTimestampVerifier::fresh($actor->verifiedAt, $now, $this->timeout);
    }
}
