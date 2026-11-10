<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Tenancy;

use Illuminate\Contracts\Container\Container;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tenancy\NullTenantDiscriminator;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;

/**
 * Builds the configured tenant discriminator (`tenancy.discriminator`): `auto` (stancl/tenancy,
 * then spatie/laravel-multitenancy, when installed; else none), `null`, `stancl`, `spatie`, or a
 * class implementing TenantDiscriminator. Neither tenancy package is required: both are detected
 * with `class_exists` and read through the container, so no class of theirs is referenced.
 */
final readonly class TenantDiscriminators
{
    public const string STANCL = 'Stancl\\Tenancy\\Tenancy';

    public const string SPATIE = 'Spatie\\Multitenancy\\Models\\Tenant';

    private const string PATH = 'laranail.settings.tenancy.discriminator';

    /** @param string $spatieBinding spatie's `multitenancy.current_tenant_container_key` */
    public function __construct(
        private Container $container,
        private string $spatieBinding = 'currentTenant',
    ) {}

    public function make(mixed $driver): TenantDiscriminator
    {
        if ($driver === 'auto') {
            $driver = match (true) {
                class_exists(self::STANCL) => 'stancl',
                class_exists(self::SPATIE) => 'spatie',
                default                    => 'null',
            };
        }

        return match ($driver) {
            'null', null => new NullTenantDiscriminator,
            'stancl'     => class_exists(self::STANCL)
                ? new ClosureTenantDiscriminator(fn (): mixed => $this->stancl())
                : throw new InvalidConfiguration(self::PATH . ': [stancl] needs stancl/tenancy.'),
            'spatie' => new ClosureTenantDiscriminator(fn (): mixed => $this->spatie()),
            default  => $this->custom($driver),
        };
    }

    /** stancl/tenancy's current tenant key, or null when tenancy is not initialised. */
    private function stancl(): mixed
    {
        $tenancy = $this->container->make(self::STANCL);

        if (! is_object($tenancy) || ! property_exists($tenancy, 'initialized') || $tenancy->initialized !== true || ! property_exists($tenancy, 'tenant')) {
            return null;
        }

        $tenant = $tenancy->tenant;

        return is_object($tenant) && method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : null;
    }

    /** spatie/laravel-multitenancy's current tenant key (its container binding), or null. */
    private function spatie(): mixed
    {
        if (! $this->container->bound($this->spatieBinding)) {
            return null;
        }

        $tenant = $this->container->make($this->spatieBinding);

        return is_object($tenant) && method_exists($tenant, 'getKey') ? $tenant->getKey() : null;
    }

    private function custom(mixed $class): TenantDiscriminator
    {
        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, TenantDiscriminator::class)) {
            throw new InvalidConfiguration(sprintf('%s: [%s] is not a built-in name or a class implementing %s.', self::PATH, is_scalar($class) ? $class : get_debug_type($class), TenantDiscriminator::class));
        }

        $instance = $this->container->make($class);

        return $instance instanceof TenantDiscriminator ? $instance : throw new InvalidConfiguration(sprintf('%s: [%s] did not build a %s.', self::PATH, $class, TenantDiscriminator::class));
    }
}
