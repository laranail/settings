<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;

/** Audit query conditions, combined with AND. `store` also picks the connection that is read. */
final readonly class AuditFilter
{
    public function __construct(
        public ?StoreName $store = null,
        public ?Scope $scope = null,
        public ?Key $key = null,
        public ?AuditAction $action = null,
        public ?AuditOutcome $outcome = null,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
    ) {}

    /** A copy with the named conditions replaced. */
    public function with(
        ?StoreName $store = null,
        ?Scope $scope = null,
        ?Key $key = null,
        ?AuditAction $action = null,
        ?AuditOutcome $outcome = null,
        ?CarbonImmutable $since = null,
        ?CarbonImmutable $until = null,
    ): self {
        return new self(
            $store ?? $this->store,
            $scope ?? $this->scope,
            $key ?? $this->key,
            $action ?? $this->action,
            $outcome ?? $this->outcome,
            $since ?? $this->since,
            $until ?? $this->until,
        );
    }
}
