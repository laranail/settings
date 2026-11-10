<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The approval rules (§16.7, `approvals.*`): how many approvals apply a change, whether the
 * requester may approve their own request, after how many days an open request expires (null:
 * never), and how many days decided requests are kept (null: for ever).
 */
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
}
