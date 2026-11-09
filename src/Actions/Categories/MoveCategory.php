<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Database\CategoryRepository;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;

/**
 * Put a category under another of the same store, or at the top level with a null parent:
 * needs `manage_categories`. A cycle raises {@see CategoryCycle}; a parent from another store
 * raises {@see CategoryStoreMismatch}.
 */
final readonly class MoveCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, string $slug, ?string $parent, ?Actor $actor = null): void
    {
        $category = new CategorySlug($slug);
        $under = $parent === null ? null : new CategorySlug($parent);
        $events = [new CategorySaving($store->name->value, $slug), new CategorySaved($store->name->value, $slug)];

        $this->guard->run($store, $category, $actor, 'move', $events[0], $events[1], static function (CategoryRepository $categories) use ($category, $under): void {
            $categories->move($category, $under);
        });
    }
}
