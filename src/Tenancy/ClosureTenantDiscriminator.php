<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tenancy;

use Closure;
use Stringable;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The tenant a closure reports. Keys longer than 64 characters are refused rather than truncated,
 * so two tenants never share a key.
 */
final readonly class ClosureTenantDiscriminator implements TenantDiscriminator
{
    /** @param Closure(): mixed $resolve */
    public function __construct(
        private Closure $resolve,
    ) {}

    public function current(): ?string
    {
        $tenant = ($this->resolve)();

        if ($tenant === null) {
            return null;
        }

        $key = match (true) {
            is_string($tenant)            => $tenant,
            is_int($tenant)               => (string) $tenant,
            $tenant instanceof Stringable => (string) $tenant,
            default                       => throw new InvalidConfiguration(sprintf('The tenant discriminator returned a %s; return a string or integer key, or null.', get_debug_type($tenant))),
        };

        if ($key === '' || strlen($key) > 64) {
            throw new InvalidConfiguration(sprintf('Tenant key [%s] must be 1-64 characters.', $key));
        }

        return $key;
    }
}
