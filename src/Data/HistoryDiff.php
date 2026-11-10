<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One setting at two versions. When `redacted`, both values are null and only `changed` is known.
 * `encrypted` marks a protected value that callers should not print unless asked.
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
