<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Closure;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The schema shape, current tenant and default store the Eloquent models read.
 *
 * Eloquent builds models with `new static`, so nothing can be injected. The provider sets closures
 * here that resolve the scoped services on each call, so no request or tenant state is held.
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
     * @param Closure(): SchemaShape $shape
     * @param Closure(): ?string $tenant
     * @param Closure(): string $defaultStore
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

    /** The tenant column value; '' outside a tenant or when tenancy is not column mode. */
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
