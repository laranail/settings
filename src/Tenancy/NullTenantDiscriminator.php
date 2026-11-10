<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tenancy;

use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;

/** No tenancy: every call runs in the central application. The default. */
final readonly class NullTenantDiscriminator implements TenantDiscriminator
{
    public function current(): ?string
    {
        return null;
    }
}
