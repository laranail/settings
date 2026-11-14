<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/** A value to write; `expectedVersion` (0 for no row yet) makes the write conditional. */
final readonly class SettingWrite
{
    public function __construct(
        public SettingTarget $target,
        public mixed $value,
        public ?int $expectedVersion = null,
    ) {}
}
