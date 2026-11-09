<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Verification;

use Closure;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Contracts\Verifier;

/**
 * Trusts {@see Actor::$verifiedAt} when it is no older than `verification.ttl` seconds: for API
 * clients and queued work that verified elsewhere (a fresh TOTP, a confirmed password).
 */
final readonly class ActorTimestampVerifier implements Verifier
{
    /** @param Closure(): CarbonImmutable $clock */
    public function __construct(
        private int $ttl,
        private Closure $clock,
    ) {}

    /** Whether a verification at `$at` is still fresh at `$now`; a timestamp in the future is not. */
    public static function fresh(?CarbonImmutable $at, CarbonImmutable $now, int $ttl): bool
    {
        if (! $at instanceof CarbonImmutable || $at->greaterThan($now)) {
            return false;
        }

        return $now->getTimestamp() - $at->getTimestamp() <= $ttl;
    }

    public function isVerified(Actor $actor, Subject $subject): bool
    {
        return self::fresh($actor->verifiedAt, ($this->clock)(), $this->ttl);
    }
}
