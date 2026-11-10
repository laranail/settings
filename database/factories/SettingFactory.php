<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database\Factories;

use Illuminate\Support\Str;
use Simtabi\Laranail\Settings\Models\Setting;
use Simtabi\Laranail\Settings\Enums\IdentityMode;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Simtabi\Laranail\Settings\Models\SettingCategory;

/**
 * Values rows for tests, written straight to a store's table. The `value` column holds the
 * encoded payload, so a plain value is JSON-encoded here exactly as the value pipeline encodes an
 * unencrypted one. Use `Settings::set()` whenever the test is about writing; use this to arrange
 * rows a test then reads.
 *
 *     Setting::factory()->forStore('settings')->key('site.name')->value('Acme')->create();
 *     Setting::factory()->owner('user', 42)->key('ui.theme')->value('dark')->create();
 *
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $key = 'test.' . Str::lower(Str::random(12));

        return $this->identity($key) + [
            'scope'   => 'global',
            'key'     => $key,
            'group'   => 'test',
            'locale'  => '',
            'value'   => json_encode(null),
            'version' => 1,
        ];
    }

    /** Rows of this store's table (default: the default store). */
    public function forStore(string $store): static
    {
        return $this->afterMaking(static function (Setting $setting) use ($store): void {
            $setting->inStore($store);
        });
    }

    public function key(string $key): static
    {
        return $this->state(fn (): array => ['key' => $key, 'group' => explode('.', $key, 2)[0]] + $this->identity($key));
    }

    /** The value, encoded as the pipeline encodes an unencrypted value. */
    public function value(mixed $value): static
    {
        return $this->state(['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    /** An owner row of a polymorphic store: scope `<alias>:<id>`. */
    public function owner(string $alias, int|string $id): static
    {
        return $this->state(['scope' => $alias . ':' . $id, 'settable_type' => $alias, 'settable_id' => (string) $id]);
    }

    public function locale(string $locale): static
    {
        return $this->state(['locale' => $locale]);
    }

    public function locked(): static
    {
        return $this->state(['is_locked' => true]);
    }

    public function inCategory(SettingCategory $category): static
    {
        return $this->state(['category_id' => $category->getKey(), 'category_store' => $category->store]);
    }

    /** @return array<string, string> the slug and uid columns the identity mode has */
    private function identity(string $key): array
    {
        $mode = ModelContext::shape()->identity;
        $columns = [];

        if ($mode->hasSlug()) {
            $columns['slug'] = $mode === IdentityMode::SlugPerRow ? sha1('global|' . $key . '|') : str_replace('.', '-', $key);
        }

        if ($mode->hasUid()) {
            $columns['uid'] = (string) Str::ulid();
        }

        return $columns;
    }
}
