<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\TenancyMode;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The configuration and current tenant the Eloquent models read.
 *
 * Eloquent builds models with `new static`, so nothing can be injected. The provider sets closures
 * here that resolve the services on each call, so no request or tenant state is held.
 */
final class ModelContext
{
    /** @var (Closure(): Repository)|null */
    private static ?Closure $config = null;

    /** @var (Closure(): ?string)|null */
    private static ?Closure $tenant = null;

    /**
     * @param Closure(): Repository $config
     * @param Closure(): ?string $tenant
     */
    public static function use(Closure $config, Closure $tenant): void
    {
        self::$config = $config;
        self::$tenant = $tenant;
    }

    /** The package's table names, from `tables`. */
    public static function names(): SchemaNames
    {
        return SchemaNames::fromConfig(self::config()->array('laranail.settings.tables', []));
    }

    /** Whether the tables carry a tenant column (`tenancy.mode` = column). */
    public static function hasTenantColumn(): bool
    {
        return TenancyMode::fromConfig(self::config()->get('laranail.settings.tenancy.mode'))->hasColumn();
    }

    /** The tenant column value; '' outside a tenant or when tenancy is not column mode. */
    public static function tenant(): string
    {
        return self::hasTenantColumn() && self::$tenant instanceof Closure ? ((self::$tenant)() ?? '') : '';
    }

    public static function defaultStore(): StoreName
    {
        return new StoreName(self::config()->string('laranail.settings.default', 'settings'));
    }

    /**
     * A store's configuration entry; empty when the store is not configured.
     *
     * @return array<array-key, mixed>
     */
    public static function store(StoreName $store): array
    {
        $config = self::config()->get('laranail.settings.stores.' . $store->value);

        return is_array($config) ? $config : [];
    }

    /** The connection of a store's first database layer; null is the default connection. */
    public static function connectionOf(StoreName $store): ?string
    {
        foreach ((array) (self::store($store)['layers'] ?? []) as $layer) {
            $config = is_string($layer) ? self::config()->get('laranail.settings.layers.' . $layer) : null;

            if (is_array($config) && ($config['driver'] ?? null) === 'database') {
                return is_string($config['connection'] ?? null) ? $config['connection'] : null;
            }
        }

        return null;
    }

    private static function config(): Repository
    {
        return self::$config instanceof Closure
            ? (self::$config)()
            : throw new InvalidConfiguration('The settings models are used before SettingsServiceProvider registered them.');
    }
}
