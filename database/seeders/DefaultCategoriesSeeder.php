<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The default categories (`laranail.settings.seed.categories`), created as `is_system` rows in
 * each store listed in `seed.stores` (default: the default store). It runs with the host's
 * `php artisan db:seed` (registered by the service provider, never named by the application's
 * DatabaseSeeder) and can be switched off with `seed.enabled`.
 *
 * Idempotent: a category whose slug the store already has is left exactly as it is, renamed
 * titles included, so running it again changes nothing. It writes through the settings API as
 * the system actor, so every category it creates is validated and audited like any other change.
 * Categories carry no credentials and the seeder writes no setting values.
 *
 * In column-mode tenancy it seeds the current tenant (run it once per tenant, for example with
 * the tenancy package's own seed command); outside a tenant it seeds the central rows.
 */
final class DefaultCategoriesSeeder extends Seeder
{
    public function run(Settings $settings, Repository $config): void
    {
        $seed = $config->get('laranail.settings.seed', []);

        if (! is_array($seed) || ($seed['enabled'] ?? true) !== true) {
            return;
        }

        foreach ($this->stores($seed, $config) as $store) {
            $manager = $settings->asSystem('laranail::settings.seed')->store($store)->categories();
            $existing = [];

            foreach ($manager->all() as $category) {
                $existing[$category->slug->value] = true;
            }

            foreach ($this->categories($seed) as $category) {
                if (! isset($existing[$category->slug->value])) {
                    $manager->create($category);
                    $existing[$category->slug->value] = true;
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $seed
     *
     * @return list<string>
     */
    private function stores(array $seed, Repository $config): array
    {
        $stores = $seed['stores'] ?? null;

        if ($stores === null || $stores === []) {
            $default = $config->get('laranail.settings.default', 'settings');

            return [is_string($default) ? $default : 'settings'];
        }

        if (! is_array($stores) || array_filter($stores, is_string(...)) !== $stores) {
            throw new InvalidConfiguration('laranail.settings.seed.stores: a list of store names.');
        }

        return array_values($stores);
    }

    /**
     * Parents first, so a child's parent always exists when the child is created.
     *
     * @param array<array-key, mixed> $seed
     *
     * @return list<NewCategory>
     */
    private function categories(array $seed): array
    {
        $entries = $seed['categories'] ?? [];

        if (! is_array($entries)) {
            throw new InvalidConfiguration('laranail.settings.seed.categories: a map of slug => attributes.');
        }

        $categories = [];
        $order = 0;

        foreach ($entries as $slug => $entry) {
            $entry = is_string($entry) ? ['title' => $entry] : $entry;

            if (! is_string($slug) || ! is_array($entry) || ! is_string($entry['title'] ?? null)) {
                throw new InvalidConfiguration(sprintf('laranail.settings.seed.categories.%s: needs at least a title.', $slug));
            }

            $parent = $entry['parent'] ?? null;
            $categories[] = new NewCategory(
                slug: $slug,
                title: $entry['title'],
                parent: is_string($parent) ? $parent : null,
                description: is_string($entry['description'] ?? null) ? $entry['description'] : null,
                icon: is_string($entry['icon'] ?? null) ? $entry['icon'] : null,
                sortOrder: is_int($entry['sort_order'] ?? null) ? $entry['sort_order'] : $order,
                translations: is_array($entry['translations'] ?? null) ? $entry['translations'] : [],
                system: true,
            );
            $order += 10;
        }

        // Each pass emits the categories whose parent is already placed; a pass that places
        // nothing means a parent is missing from the list or the parents form a cycle.
        $ordered = [];
        $placed = [];

        while ($categories !== []) {
            $before = count($categories);

            foreach ($categories as $index => $category) {
                if ($category->parent === null || isset($placed[$category->parent->value])) {
                    $ordered[] = $category;
                    $placed[$category->slug->value] = true;
                    unset($categories[$index]);
                }
            }

            if (count($categories) === $before) {
                throw new InvalidConfiguration(sprintf('laranail.settings.seed.categories: [%s] name a parent that is not in the list, or parents that form a cycle.', implode(', ', array_map(static fn (NewCategory $category): string => $category->slug->value, $categories))));
            }
        }

        return $ordered;
    }
}
