<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * The input of {@see \Simtabi\Laranail\Settings\Actions\SetSetting}: a target and the value to
 * store there.
 */
final readonly class SettingWrite
{
    public function __construct(
        public SettingTarget $target,
        public mixed $value,
    ) {}
}
