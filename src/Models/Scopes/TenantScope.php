<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models\Scopes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Models\SettingsModel;

/**
 * Limits queries to the current tenant when `tenancy.mode` is `column`; does nothing otherwise.
 * Remove it with `withoutGlobalScope(TenantScope::class)`, or the models' `allTenants()` scope, to work
 * across tenants.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class TenantScope implements Scope
{
    /**
     * @param Builder<covariant TModel> $builder
     * @param TModel $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($model instanceof SettingsModel && $model->usesTenantColumn() && ModelContext::hasTenantColumn()) {
            $builder->where($model->qualifyColumn('tenant'), ModelContext::tenant());
        }
    }
}
