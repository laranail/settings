<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Verification;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Container\Attributes\Config;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Contracts\Verifier;

/**
 * Trusts {@see Actor::$verifiedAt} when it is no older than `verification.ttl` seconds.
 */
final readonly class ActorTimestampVerifier implements Verifier
{
    /** @param int $ttl seconds a verification stays fresh */
    public function __construct(
        #[Config('laranail.settings.verification.ttl', 900)]
        private int $ttl,
        private Clock $clock,
    ) {}

    /** Whether a verification at `$at` is still fresh; a future timestamp is not. */
    public static function fresh(?CarbonImmutable $at, CarbonImmutable $now, int $ttl): bool
    {
        if (! $at instanceof CarbonImmutable || $at->greaterThan($now)) {
            return false;
        }

        return $now->getTimestamp() - $at->getTimestamp() <= $ttl;
    }

    public function isVerified(Actor $actor, Subject $subject): bool
    {
        return self::fresh($actor->verifiedAt, $this->clock->now(), $this->ttl);
    }
}
