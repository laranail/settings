<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Events\CategoryMoved;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;

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

        $this->guard->run($store, $category, $actor, 'move', $events[0], $events[1], static function (DatabaseDriver $database) use ($store, $category, $under): void {
            $connection = $database->connectionName();
            $id = SettingCategory::idOf($connection, $store->name, $category);
            $parent = $under instanceof CategorySlug ? SettingCategory::idOf($connection, $store->name, $under) : null;

            if ($parent !== null && in_array($id, [$parent, ...SettingCategory::ancestorsOf($connection, $parent)], true)) {
                throw new CategoryCycle(sprintf('Category [%s] in store [%s] cannot move under [%s]: that is itself or one of its descendants.', $category, $store->name, $under));
            }

            SettingCategory::on($connection)->whereKey($id)->update(['parent_id' => $parent]);
        }, [new CategoryMoved($store->name->value, $slug, $parent)]);
    }
}
