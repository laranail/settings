<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\StoreName;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Database\Factories\SettingFactory;

/**
 * A row of a store's values table, for factories, admin screens and tests. Belongs to the default
 * store unless {@see inStore()} or {@see forStore()} names another.
 *
 * `value` is the encoded payload. Writing it here bypasses validation, encryption, locks,
 * authorization and audit; application code uses `Settings::set()`. The engine reads and writes
 * this table through the query builder, using {@see tableFor()} for its name.
 *
 * @property string $id
 * @property string $uid
 * @property string $scope
 * @property string $key
 * @property string $locale
 * @property ?string $value
 * @property int $version
 */
final class Setting extends SettingsModel
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    private ?string $store = null;

    /** A store's values table: `tables.prefix` plus `stores.<name>.table`, else the store name. */
    public static function tableFor(StoreName $store, ?string $table = null): string
    {
        $configured = ModelContext::store($store)['table'] ?? null;

        return ModelContext::names()->store($store, $table ?? (is_string($configured) ? $configured : null));
    }

    /** @return Builder<self> */
    public static function forStore(string $store): Builder
    {
        return new self()->inStore($store)->newQuery();
    }

    /** Point this instance at a store's values table and connection. */
    public function inStore(string $store): static
    {
        $this->store = new StoreName($store)->value;
        $this->setTable(self::tableFor($this->storeName()));
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
        return $this->table ?? self::tableFor($this->storeName());
    }

    /**
     * Both keys are ULIDs.
     *
     * @return list<string>
     */
    #[Override]
    public function uniqueIds(): array
    {
        return ['id', 'uid'];
    }

    /**
     * Copies keep the store.
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
        // Trashed rows are hidden in stores that keep forgotten rows (`stores.<name>.soft_deletes`).
        self::addGlobalScope('laranail-settings.live', static function (Builder $query): void {
            $model = $query->getModel();

            if ($model instanceof self && (ModelContext::store($model->storeName())['soft_deletes'] ?? false) === true) {
                $query->whereNull($query->qualifyColumn('deleted_at'));
            }
        });
    }

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }

    #[Override]
    protected function defaultConnectionName(): ?string
    {
        return ModelContext::connectionOf($this->storeName());
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'label'        => 'array',
            'description'  => 'array',
            'is_encrypted' => 'boolean',
            'is_locked'    => 'boolean',
            'is_public'    => 'boolean',
            'autoload'     => 'boolean',
            'sort_order'   => 'integer',
            'version'      => 'integer',
            'meta'         => 'array',
        ];
    }
}
