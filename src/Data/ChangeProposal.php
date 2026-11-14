<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\ChangeOperation;

/** The write a change request applies once approved; `value` is used by a set only. */
final readonly class ChangeProposal
{
    public function __construct(
        public SettingTarget $target,
        public ChangeOperation $operation,
        public mixed $value = null,
    ) {}
}
