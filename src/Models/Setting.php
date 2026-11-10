<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Database\Factories\SettingFactory;
use Simtabi\Laranail\Settings\Models\Concerns\UsesSettingsSchema;

/**
 * A row of one store's values table (§4.2), for factories, admin screens and tests. Each store
 * has its own table, so a model instance belongs to one store: the default store unless
 * {@see inStore()} or {@see forStore()} names another.
 *
 * `value` is the **encoded payload** (the value pipeline's JSON, encrypted when `is_encrypted`).
 * Writing it here bypasses validation, casting, encryption, locks, authorization and audit, so
 * application code sets values with `Settings::set()`; the engine never reads through this model.
 *
 * @property int|string $id
 * @property string $scope
 * @property string $key
 * @property string $locale
 * @property ?string $value
 * @property int $version
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    use UsesSettingsSchema;

    protected $guarded = [];

    private ?string $store = null;

    /** @return Builder<self> a query on a store's values table */
    public static function forStore(string $store): Builder
    {
        return new self()->inStore($store)->newQuery();
    }

    /** Point this instance at a store's values table and connection. */
    public function inStore(string $store): static
    {
        $this->store = new StoreName($store)->value;
        $this->setTable($this->storeTable());
        $this->setConnection(ModelContext::connectionOf($this->storeName()));

        return $this;
    }

    public function storeName(): StoreName
    {
        return $this->store === null ? ModelContext::defaultStore() : new StoreName($this->store);
    }

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? $this->storeTable();
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? ModelContext::connectionOf($this->storeName());
    }

    /**
     * Copies keep the store, so rows read from a store's table stay in that store.
     *
     * @param array<string, mixed> $attributes
     */
    #[Override]
    public function newInstance($attributes = [], $exists = false): static
    {
        $model = parent::newInstance($attributes, $exists);
        $model->store = $this->store;

        return $model;
    }

    /** @return BelongsTo<SettingCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SettingCategory::class, 'category_id');
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('laranail-settings.live', static function (Builder $query): void {
            $model = $query->getModel();

            if ($model instanceof self && ModelContext::shape()->store($model->storeName())->softDeletes) {
                $query->whereNull($query->qualifyColumn('deleted_at'));
            }
        });
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'is_locked'    => 'boolean',
            'is_public'    => 'boolean',
            'autoload'     => 'boolean',
            'sort_order'   => 'integer',
            'version'      => 'integer',
            'meta'         => 'array',
        ];
    }

    private function storeTable(): string
    {
        $shape = ModelContext::shape();
        $store = $this->storeName();

        return $shape->names->store($store, $shape->store($store)->table);
    }
}
