<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

/**
 * The current tenant (at most 64 characters), or null outside any tenant. It also keys in-memory
 * state, so one tenant never reads another's settings.
 */
interface TenantDiscriminator
{
    public function current(): ?string;
}
