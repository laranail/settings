<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Closure;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * What the Eloquent models need to know and cannot ask for themselves (Eloquent builds models
 * with `new static`, so nothing is injected): the recorded schema shape (table names, key type,
 * soft deletes, tenancy mode), the current tenant and the default store. The service provider
 * sets it once with closures that resolve the scoped services on every call, so nothing derived
 * from one request or tenant is held here.
 *
 * The models are for factories, seeders, admin screens and tests. The settings engine never uses
 * them: reads and writes go through the drivers on the query builder.
 */
final class ModelContext
{
    /** @var (Closure(): SchemaShape)|null */
    private static ?Closure $shape = null;

    /** @var (Closure(): ?string)|null */
    private static ?Closure $tenant = null;

    /** @var (Closure(): string)|null */
    private static ?Closure $defaultStore = null;

    /**
     * @param Closure(): SchemaShape $shape the recorded shape (tables, keys, tenancy mode)
     * @param Closure(): ?string $tenant the current tenant
     * @param Closure(): string $defaultStore `laranail.settings.default`
     */
    public static function use(Closure $shape, Closure $tenant, Closure $defaultStore): void
    {
        self::$shape = $shape;
        self::$tenant = $tenant;
        self::$defaultStore = $defaultStore;
    }

    public static function shape(): SchemaShape
    {
        return self::$shape instanceof Closure
            ? (self::$shape)()
            : throw new InvalidConfiguration('The settings models are used before SettingsServiceProvider registered them.');
    }

    /** The tenant column value: '' outside any tenant, or when tenancy is not column mode. */
    public static function tenant(): string
    {
        return self::hasTenantColumn() && self::$tenant instanceof Closure ? ((self::$tenant)() ?? '') : '';
    }

    public static function hasTenantColumn(): bool
    {
        return self::shape()->tenancy->hasColumn();
    }

    public static function defaultStore(): StoreName
    {
        return new StoreName(self::$defaultStore instanceof Closure ? (self::$defaultStore)() : 'settings');
    }

    /** The connection a store's tables live on; null is the default connection. */
    public static function connectionOf(StoreName $store): ?string
    {
        return self::shape()->store($store)->connection;
    }
}
