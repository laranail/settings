<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Models\Concerns\UsesSettingsSchema;
use Simtabi\Laranail\Settings\Database\Factories\SettingCategoryFactory;

/**
 * A row of the categories table (§4.1), for factories, seeders and admin screens. Category
 * changes made through the settings API (`Settings::categories()`) are authorized, validated
 * and audited; changes made through this model are not, so application code should prefer the
 * API and keep the model to tests and seeding.
 *
 * Trashed categories (`categories.soft_deletes`) are hidden, as the engine hides them.
 *
 * @property int|string $id
 * @property string $store
 * @property int|string|null $parent_id
 * @property string $title
 * @property string $slug
 * @property ?string $description
 * @property ?string $icon
 * @property int $sort_order
 * @property bool $is_system
 * @property ?array<array-key, mixed> $meta
 */
final class SettingCategory extends Model
{
    /** @use HasFactory<SettingCategoryFactory> */
    use HasFactory;

    use UsesSettingsSchema;

    protected $guarded = [];

    #[Override]
    public function getTable(): string
    {
        return ModelContext::shape()->names->categories();
    }

    #[Override]
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? ModelContext::connectionOf(ModelContext::defaultStore());
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForStore(Builder $query, string $store): Builder
    {
        return $query->where('store', $store);
    }

    #[Override]
    protected static function booted(): void
    {
        self::addGlobalScope('laranail-settings.live', static function (Builder $query): void {
            if (ModelContext::shape()->categoriesSoftDeletes) {
                $query->whereNull($query->qualifyColumn('deleted_at'));
            }
        });
    }

    protected static function newFactory(): SettingCategoryFactory
    {
        return SettingCategoryFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'sort_order' => 'integer', 'meta' => 'array'];
    }
}
