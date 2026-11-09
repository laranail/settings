<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\CategoryDeleted;
use Simtabi\Laranail\Settings\Events\CategoryDeleting;
use Simtabi\Laranail\Settings\Database\CategoryRepository;

/**
 * Delete a category: needs `manage_categories`. Its children move to the top level and the
 * settings in it keep their values but lose the category.
 */
final readonly class DeleteCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, string $slug, ?Actor $actor = null): void
    {
        $category = new CategorySlug($slug);
        $events = [new CategoryDeleting($store->name->value, $slug), new CategoryDeleted($store->name->value, $slug)];

        $this->guard->run($store, $category, $actor, 'delete', $events[0], $events[1], static function (CategoryRepository $categories, DatabaseDriver $database) use ($category, $store): void {
            $categories->delete($category, $database->tableFor($store->name));
        });
    }
}
