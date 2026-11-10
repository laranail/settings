<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;

/**
 * Which history entries to read: one store, optionally narrowed to a scope, key and locale, a
 * time window and a version range (inclusive).
 */
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

    /** A copy with some conditions replaced (named arguments; the rest are kept). */
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
