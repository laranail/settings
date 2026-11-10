<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database\Factories;

use Illuminate\Support\Str;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Models\SettingCategory;

/**
 * Categories for tests and seeding. Titles are JSON maps of locale to text. No Faker: slugs and titles are generated, so host applications need not
 * install it.
 *
 *     SettingCategory::factory()->forStore('user_settings')->system()->create(['slug' => 'mail']);
 *     SettingCategory::factory()->parent($general)->create();
 *
 * @extends Factory<SettingCategory>
 */
final class SettingCategoryFactory extends Factory
{
    protected $model = SettingCategory::class;

    /**
     * Text as the engine stores it: `['' => text]`, the text for any locale.
     *
     * @return array<string, string>
     */
    public static function text(string $text): array
    {
        return Translations::of($text);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = 'category-' . Str::lower(Str::random(10));

        return [
            'store'       => ModelContext::defaultStore()->value,
            'slug'        => $slug,
            'title'       => self::text(Str::headline($slug)),
            'description' => null,
            'icon'        => null,
            'sort_order'  => 0,
            'is_system'   => false,
        ];
    }

    /** In this store (default: the default store). */
    public function forStore(string $store): static
    {
        return $this->state(['store' => $store]);
    }

    /** Shipped by code: `is_system`, which UIs do not let anyone delete. */
    public function system(): static
    {
        return $this->state(['is_system' => true]);
    }

    /** Under a parent, in the parent's store. */
    public function parent(SettingCategory $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey(), 'store' => $parent->store]);
    }

    /** A title, and optionally a description. */
    public function titled(string $title, ?string $description = null): static
    {
        return $this->state(['title' => self::text($title), 'description' => $description === null ? null : self::text($description)]);
    }
}
