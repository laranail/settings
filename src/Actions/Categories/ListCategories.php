<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;

/** List a store's categories by sort order, then slug. */
final readonly class ListCategories
{
    public function __construct(
        private AccessGuard $access,
    ) {}

    /** @return list<Category> */
    public function handle(Store $store, ?Actor $actor = null): array
    {
        $this->access->authorize($actor, Ability::View, new Subject($store->name));

        $database = $store->requireDatabase('; categories are kept in the database.');

        return $database->attempt($store->name, static fn (): array => SettingCategory::listFor($database->connectionName(), $store->name));
    }
}
