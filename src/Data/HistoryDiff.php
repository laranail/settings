<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * The value of one setting at two versions. `redacted` is true when the setting is encrypted and
 * the actor may not view sensitive values: both values are then null and only `changed` (whether
 * the stored payloads differ) is reported. A version that holds no value (a tombstone, or a lock
 * row without one) reads as null with `fromFound` / `toFound` false. `encrypted` says the value is
 * protected (encrypted at rest, or its definition is sensitive), so a caller printing it, such as
 * the history command, can hold it back unless asked.
 */
final readonly class HistoryDiff
{
    public function __construct(
        public string $store,
        public string $scope,
        public string $key,
        public string $locale,
        public int $fromVersion,
        public int $toVersion,
        public mixed $from,
        public mixed $to,
        public bool $fromFound,
        public bool $toFound,
        public bool $changed,
        public bool $redacted,
        public bool $encrypted = false,
    ) {}
}
