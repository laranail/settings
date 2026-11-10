<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;

/**
 * Create a category in a store, optionally under a parent of the same store: needs
 * `manage_categories`.
 */
final readonly class CreateCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, NewCategory $category, ?Actor $actor = null): Category
    {
        $events = [new CategorySaving($store->name->value, $category->slug->value), new CategorySaved($store->name->value, $category->slug->value)];

        return $this->guard->run($store, $category->slug, $actor, 'create', $events[0], $events[1], static fn (CategoryRepository $categories): Category => $categories->create($category));
    }
}
