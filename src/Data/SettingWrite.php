<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * The input of {@see \Simtabi\Laranail\Settings\Actions\SetSetting}: a target and the value to
 * store there. `expectedVersion`, when set, makes the write conditional on the row still being
 * at that version (0: no row yet), as a REST `If-Match` will; such a write is never retried.
 */
final readonly class SettingWrite
{
    public function __construct(
        public SettingTarget $target,
        public mixed $value,
        public ?int $expectedVersion = null,
    ) {}
}
