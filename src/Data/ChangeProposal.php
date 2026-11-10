<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Enums\ChangeOperation;

/**
 * The input of {@see \Simtabi\Laranail\Settings\Actions\Approvals\ProposeChange}: the write a
 * change request will apply once approved. `value` matters for a set only.
 */
final readonly class ChangeProposal
{
    public function __construct(
        public SettingTarget $target,
        public ChangeOperation $operation,
        public mixed $value = null,
    ) {}
}
