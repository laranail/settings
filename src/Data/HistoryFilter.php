<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;

/** History query conditions: a store, optionally narrowed by scope, key, locale, time and version. */
final readonly class HistoryFilter
{
    public function __construct(
        public ?StoreName $store = null,
        public ?Scope $scope = null,
        public ?Key $key = null,
        public ?Locale $locale = null,
        public ?CarbonImmutable $since = null,
        public ?CarbonImmutable $until = null,
        public ?int $fromVersion = null,
        public ?int $toVersion = null,
    ) {}

    /** A copy with the named conditions replaced. */
    public function with(
        ?StoreName $store = null,
        ?Scope $scope = null,
        ?Key $key = null,
        ?Locale $locale = null,
        ?CarbonImmutable $since = null,
        ?CarbonImmutable $until = null,
        ?int $fromVersion = null,
        ?int $toVersion = null,
    ): self {
        return new self(
            $store ?? $this->store,
            $scope ?? $this->scope,
            $key ?? $this->key,
            $locale ?? $this->locale,
            $since ?? $this->since,
            $until ?? $this->until,
            $fromVersion ?? $this->fromVersion,
            $toVersion ?? $this->toVersion,
        );
    }
}
