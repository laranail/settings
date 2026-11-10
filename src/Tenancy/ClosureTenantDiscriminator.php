<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tenancy;

use Closure;
use Stringable;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The tenant a closure reports: how the stancl/tenancy and spatie/laravel-multitenancy adapters
 * are built (the closure asks the tenancy package), and a way for an application to plug in its
 * own. An integer or Stringable key is turned into a string; anything else, or a key longer than
 * 64 characters, is refused rather than truncated, because two tenants must never share a key.
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
