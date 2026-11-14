<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingCategory;

/** Create a category, optionally under a parent in the same store. */
final readonly class CreateCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, NewCategory $category, ?Actor $actor = null): Category
    {
        $events = [new CategorySaving($store->name->value, $category->slug->value), new CategorySaved($store->name->value, $category->slug->value)];

        return $this->guard->run($store, $category->slug, $actor, 'create', $events[0], $events[1], static function (DatabaseDriver $database) use ($store, $category): Category {
            $connection = $database->connectionName();
            $parent = $category->parent instanceof CategorySlug ? SettingCategory::idOf($connection, $store->name, $category->parent) : null;

            return SettingCategory::on($connection)->create([
                'store'       => $store->name->value,
                'slug'        => $category->slug->value,
                'parent_id'   => $parent,
                'title'       => Translations::of($category->title, $category->translations),
                'description' => Translations::ofNullable($category->description),
                'icon'        => $category->icon,
                'sort_order'  => $category->sortOrder,
                'is_system'   => $category->system,
            ])->toCategory($category->parent);
        });
    }
}
