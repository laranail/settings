<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Simtabi\Laranail\Settings\Support\Translations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Models\Scopes\TenantScope;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Database\Factories\SettingCategoryFactory;

/**
 * A row of the categories table. Categories belong to one store and nest by `parent_id`; titles and
 * descriptions are JSON maps of locale to text. Changes made directly here skip authorization and
 * audit; application code uses `Settings::categories()`.
 *
 * @property string $id
 * @property string $store
 * @property ?string $parent_id
 * @property array<string, string> $title
 * @property string $slug
 * @property ?array<string, string> $description
 * @property ?string $icon
 * @property int $sort_order
 * @property bool $is_system
 * @property ?array<array-key, mixed> $meta
 */
final class SettingCategory extends SettingsModel
{
    /** @use HasFactory<SettingCategoryFactory> */
    use HasFactory;

    /**
     * A store's categories as data, by sort order then slug.
     *
     * @return list<Category>
     */
    public static function listFor(?string $connection, StoreName $store): array
    {
        $rows = self::on($connection)->forStore($store)->orderBy('sort_order')->orderBy('slug')->get();
        $slugs = $rows->pluck('slug', 'id')->all();

        return array_values($rows->map(static fn (self $row): Category => $row->toCategory(
            $row->parent_id !== null && is_string($slugs[$row->parent_id] ?? null) ? new CategorySlug($slugs[$row->parent_id]) : null,
        ))->all());
    }

    /**
     * The id of a store's category.
     *
     * @throws CategoryStoreMismatch when the slug belongs to another store only
     * @throws UnknownCategory
     */
    public static function idOf(?string $connection, StoreName $store, CategorySlug $slug): string
    {
        $id = self::on($connection)->forStore($store)->where('slug', $slug->value)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $elsewhere = self::on($connection)->where('slug', $slug->value)->value('store');

        throw is_string($elsewhere)
            ? new CategoryStoreMismatch(sprintf('Category [%s] belongs to store [%s], not [%s].', $slug, $elsewhere, $store))
            : new UnknownCategory(sprintf('Store [%s] has no category [%s].', $store, $slug));
    }

    /**
     * The ids above a category, nearest first.
     *
     * @return list<string>
     *
     * @throws CategoryCycle when the data already holds a cycle
     */
    public static function ancestorsOf(?string $connection, string $id): array
    {
        $ancestors = [];

        // Bounded, so a cycle already in the data cannot loop forever.
        for ($guard = 0; $guard < 10_000; $guard++) {
            $parent = self::on($connection)->whereKey($id)->value('parent_id');

            if (! is_string($parent)) {
                return $ancestors;
            }

            $ancestors[] = $parent;
            $id = $parent;
        }

        throw new CategoryCycle('The categories already contain a cycle; repair them in the database.');
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

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? ModelContext::names()->categories();
    }

    /** This row as data, with its parent's slug. */
    public function toCategory(?CategorySlug $parent = null): Category
    {
        return new Category(
            id: $this->id,
            store: new StoreName($this->store),
            slug: new CategorySlug($this->slug),
            title: Translations::text($this->title) ?? '',
            parent: $parent,
            description: Translations::text($this->description),
            icon: $this->icon,
            sortOrder: $this->sort_order,
            system: $this->is_system,
            titles: Translations::texts($this->title),
        );
    }

    /**
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeForStore(Builder $query, StoreName|string $store): Builder
    {
        return $query->where('store', $store instanceof StoreName ? $store->value : $store);
    }

    /**
     * The central categories ('' in column-mode tenancy), which every tenant's definitions use.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeCentral(Builder $query): Builder
    {
        $query->withoutGlobalScope(TenantScope::class);

        return ModelContext::hasTenantColumn() ? $query->where('tenant', '') : $query;
    }

    protected static function newFactory(): SettingCategoryFactory
    {
        return SettingCategoryFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return ['title' => 'array', 'description' => 'array', 'is_system' => 'boolean', 'sort_order' => 'integer', 'meta' => 'array'];
    }
}
