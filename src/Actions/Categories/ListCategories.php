<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;

/** A store's categories, by sort order then slug: needs `view` on the store. */
final readonly class ListCategories
{
    public function __construct(
        private AccessGuard $access,
    ) {}

    /** @return list<Category> */
    public function handle(Store $store, ?Actor $actor = null): array
    {
        $this->access->authorize($actor, Ability::View, new Subject($store->name));

        return CategoryRepository::of($store)->all();
    }
}
