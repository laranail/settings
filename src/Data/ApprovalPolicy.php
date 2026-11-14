<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/** The approval rules from `approvals.*`. Null days mean never expire, or keep for ever. */
final readonly class ApprovalPolicy
{
    public function __construct(
        public int $minApprovals = 1,
        public bool $allowSelf = false,
        public ?int $expireAfterDays = 7,
        public ?int $keepDays = 90,
    ) {
        if ($minApprovals < 1) {
            throw new InvalidConfiguration(sprintf('laranail.settings.approvals.min_approvals: [%d] must be at least 1.', $minApprovals));
        }

        foreach (['expire_after' => $expireAfterDays, 'keep_days' => $keepDays] as $name => $days) {
            if ($days !== null && $days < 1) {
                throw new InvalidConfiguration(sprintf('laranail.settings.approvals.%s: [%d] must be at least 1, or null.', $name, $days));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $approvals `laranail.settings.approvals`
     */
    public static function fromConfig(array $approvals): self
    {
        $days = static function (string $key, ?int $default) use ($approvals): ?int {
            $value = array_key_exists($key, $approvals) ? $approvals[$key] : $default;

            return $value === null ? null : (is_numeric($value) ? (int) $value : throw new InvalidConfiguration(sprintf('laranail.settings.approvals.%s: a number of days, or null.', $key)));
        };
        $min = $approvals['min_approvals'] ?? 1;

        return new self(
            is_int($min) || (is_string($min) && ctype_digit($min)) ? (int) $min : 1,
            ($approvals['allow_self'] ?? false) === true,
            $days('expire_after', 7),
            $days('keep_days', 90),
        );
    }
}
