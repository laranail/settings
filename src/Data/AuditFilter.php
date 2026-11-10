<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;

/**
 * What an audit query selects: every condition is optional and they combine with AND. `store`
 * also picks the connection whose audit table is read (the default store's when null).
 */
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

    /** A copy with some conditions replaced (named arguments; the rest are kept). */
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
