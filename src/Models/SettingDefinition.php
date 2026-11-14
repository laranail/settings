<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Models;

use Override;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Support\Translations;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Settings\Models\Scopes\TenantScope;
use Simtabi\Laranail\Settings\Database\Factories\SettingDefinitionFactory;

/**
 * A row of the definitions table, on the default store's connection. Shared by every tenant, so it
 * has no tenant column. `is_system` rows are synced from code and config; the others are edited at
 * runtime.
 *
 * @property string $id
 * @property string $store
 * @property string $key
 * @property string $slug
 * @property string $group
 * @property ?string $category_id
 * @property ?string $type
 * @property ?string $default
 * @property ?array<string, string> $label
 * @property ?array<string, string> $description
 * @property ?list<string> $rules
 * @property ?list<string> $overridable_by
 * @property ?bool $inherits
 * @property bool $is_encrypted
 * @property bool $is_public
 * @property bool $is_locked
 * @property bool $is_system
 * @property-read ?SettingCategory $category
 */
final class SettingDefinition extends SettingsModel
{
    /** @use HasFactory<SettingDefinitionFactory> */
    use HasFactory;

    #[Override]
    public function getTable(): string
    {
        return $this->table ?? ModelContext::names()->definitions();
    }

    /** @return BelongsTo<SettingCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SettingCategory::class, 'category_id')->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Fill the columns a definition describes. A category links to the store's central category
     * of that slug, since definitions are shared by every tenant.
     *
     * @param ?string $default the encoded default; an encrypted definition's default is never stored
     * @param array<array-key, mixed>|null $categories the store's central category ids by slug, when the caller loaded them once; null looks the slug up
     */
    public function fillFromDefinition(Definition $definition, ?string $default, ?array $categories = null): static
    {
        $category = match (true) {
            $definition->category === null => null,
            $categories !== null           => $categories[$definition->category] ?? null,
            default                        => $this->categoryId($definition->category),
        };

        return $this->fill([
            'slug'           => $definition->key->slug(),
            'group'          => $definition->group(),
            'category_id'    => is_string($category) ? $category : null,
            'type'           => $definition->type,
            'default'        => $definition->encrypted ? null : $default,
            'label'          => Translations::ofNullable($definition->label),
            'description'    => Translations::ofNullable($definition->description),
            'rules'          => $definition->rules,
            'is_encrypted'   => $definition->encrypted,
            'is_public'      => $definition->public,
            'is_locked'      => $definition->locked,
            'inherits'       => $definition->inherits,
            'overridable_by' => $definition->overridableBy,
        ]);
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
     * Rows edited at runtime, not synced from code or config.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeRuntime(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /** Definitions are shared by every tenant, so the table has no tenant column. */
    #[Override]
    public function usesTenantColumn(): bool
    {
        return false;
    }

    protected static function newFactory(): SettingDefinitionFactory
    {
        return SettingDefinitionFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'label'          => 'array',
            'description'    => 'array',
            'rules'          => 'array',
            'overridable_by' => 'array',
            'meta'           => 'array',
            'is_encrypted'   => 'boolean',
            'is_public'      => 'boolean',
            'is_locked'      => 'boolean',
            'autoload'       => 'boolean',
            'inherits'       => 'boolean',
            'is_system'      => 'boolean',
        ];
    }

    /** The id of the store's central category with this slug, or null. */
    private function categoryId(string $slug): mixed
    {
        return SettingCategory::on($this->getConnectionName())
            ->central()
            ->forStore($this->store)
            ->where('slug', $slug)
            ->value('id');
    }
}
