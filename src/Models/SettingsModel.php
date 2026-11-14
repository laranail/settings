<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Simtabi\Laranail\Settings\Models\Scopes\TenantScope;
use Simtabi\Laranail\Settings\Models\Concerns\BelongsToTenant;

/**
 * The base of the package's models: ULID keys, the current tenant in column-mode tenancy, and the
 * default store's connection unless a query names another with `on()`.
 */
abstract class SettingsModel extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $guarded = [];

    #[Override]
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? $this->defaultConnectionName();
    }

    /**
     * Every tenant's rows, for maintenance that works across tenants.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    public function scopeAllTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }

    /** Whether this table has the tenant column in column-mode tenancy. */
    public function usesTenantColumn(): bool
    {
        return true;
    }

    /** JSON columns keep Unicode and slashes readable, as the engine writes them. */
    #[Override]
    protected function asJson($value, $flags = 0)
    {
        return parent::asJson($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The connection used when no query named one: the default store's. */
    protected function defaultConnectionName(): ?string
    {
        return ModelContext::connectionOf(ModelContext::defaultStore());
    }
}
