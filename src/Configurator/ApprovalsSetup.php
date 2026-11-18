<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** `approvals.*`, inside `SettingsSetup::approvals()`. */
final readonly class ApprovalsSetup
{
    public function __construct(private SetupValues $values) {}

    public function minApprovals(int $approvals): self
    {
        $this->values->set('approvals.min_approvals', SetupValues::atLeast('approvals()->minApprovals()', 'approvals.min_approvals', $approvals, 1));

        return $this;
    }

    /** `approvals.allow_self`: let the proposer approve their own change. */
    public function allowSelf(bool $allow = true): self
    {
        $this->values->set('approvals.allow_self', $allow);

        return $this;
    }

    /** `approvals.expire_after`: days an open change request stays open. */
    public function expireAfter(int $days): self
    {
        $this->values->set('approvals.expire_after', SetupValues::atLeast('approvals()->expireAfter()', 'approvals.expire_after', $days, 1));

        return $this;
    }

    /** `approvals.keep_days`: days closed change requests are kept before pruning. */
    public function keepDays(int $days): self
    {
        $this->values->set('approvals.keep_days', SetupValues::atLeast('approvals()->keepDays()', 'approvals.keep_days', $days, 1));

        return $this;
    }
}
