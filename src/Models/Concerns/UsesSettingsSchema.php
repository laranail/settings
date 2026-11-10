<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;

/**
 * The shared shape of the settings models: the primary key follows `ids.type` (bigint keys are
 * assigned by the database, uuid and ulid keys are generated on create), and in column-mode
 * tenancy every query is limited to the current tenant and every new row is stamped with it.
 *
 * @phpstan-require-extends Model
 */
trait UsesSettingsSchema
{
    public static function bootUsesSettingsSchema(): void
    {
        static::addGlobalScope('laranail-settings.tenant', static function (Builder $query): void {
            $model = $query->getModel();

            if (ModelContext::hasTenantColumn() && method_exists($model, 'usesTenantColumn') && $model->usesTenantColumn()) {
                $query->where($query->qualifyColumn('tenant'), ModelContext::tenant());
            }
        });

        static::creating(static function (self $model): void {
            if (ModelContext::hasTenantColumn() && $model->usesTenantColumn() && $model->getAttribute('tenant') === null) {
                $model->setAttribute('tenant', ModelContext::tenant());
            }

            if ($model->getKey() === null) {
                foreach (DatabaseDriver::newId($model->idType()) as $column => $id) {
                    $model->setAttribute($column, $id);
                }
            }
        });
    }

    public function getIncrementing(): bool
    {
        return $this->idType() === IdType::Bigint;
    }

    public function getKeyType(): string
    {
        return $this->idType() === IdType::Bigint ? 'int' : 'string';
    }

    /** Whether this table carries the tenant column in column-mode tenancy. */
    public function usesTenantColumn(): bool
    {
        return true;
    }

    protected function idType(): IdType
    {
        return ModelContext::shape()->ids;
    }
}
