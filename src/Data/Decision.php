<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\Verdict;

/** An authorizer's answer: allow, deny, require verification, require approval, or abstain. */
final readonly class Decision
{
    private function __construct(
        public Verdict $verdict,
        public ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(Verdict::Allow);
    }

    public static function deny(?string $reason = null): self
    {
        return new self(Verdict::Deny, $reason);
    }

    public static function requiresVerification(?string $reason = null): self
    {
        return new self(Verdict::RequiresVerification, $reason);
    }

    public static function requiresApproval(?string $reason = null): self
    {
        return new self(Verdict::RequiresApproval, $reason);
    }

    /** No opinion; the next authorizer decides. */
    public static function abstain(?string $reason = null): self
    {
        return new self(Verdict::Abstain, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->verdict === Verdict::Allow;
    }

    public function isDenied(): bool
    {
        return $this->verdict === Verdict::Deny;
    }

    public function isAbstention(): bool
    {
        return $this->verdict === Verdict::Abstain;
    }

    public function needsVerification(): bool
    {
        return $this->verdict === Verdict::RequiresVerification;
    }

    public function needsApproval(): bool
    {
        return $this->verdict === Verdict::RequiresApproval;
    }
}
