<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

/**
 * The current tenant, as a string of at most 64 characters, or null outside any tenant (the
 * central application). In column-mode tenancy it is written to and filtered on every row; in
 * every mode it keys what is held in memory, so one tenant's settings are never answered from
 * another tenant's reads.
 */
interface TenantDiscriminator
{
    public function current(): ?string;
}
