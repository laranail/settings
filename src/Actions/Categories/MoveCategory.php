<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Events\CategoryMoved;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;

/** Move a category under another in the same store, or to the top level with a null parent. */
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
        }, [new CategoryMoved($store->name->value, $slug, $parent)]);
    }
}
