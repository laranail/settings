<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models\Concerns;

use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Models\SettingsModel;
use Simtabi\Laranail\Settings\Models\Scopes\TenantScope;

/**
 * In column-mode tenancy, limits queries to the current tenant and fills `tenant` on new rows.
 *
 * @phpstan-require-extends SettingsModel
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (self $model): void {
            if ($model->usesTenantColumn() && ModelContext::hasTenantColumn() && $model->getAttribute('tenant') === null) {
                $model->setAttribute('tenant', ModelContext::tenant());
            }
        });
    }
}
