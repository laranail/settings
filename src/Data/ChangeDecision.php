<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Stores\Store;

/** Which change request to approve, reject or cancel, and why. */
final readonly class ChangeDecision
{
    public function __construct(
        public Store $store,
        public string $id,
        public ?string $reason = null,
    ) {}
}
