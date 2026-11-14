<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\CategoryDeleted;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Events\CategoryDeleting;

/** Delete a category; its children move to the top level and its settings keep their values. */
final readonly class DeleteCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, string $slug, ?Actor $actor = null): void
    {
        $category = new CategorySlug($slug);
        $events = [new CategoryDeleting($store->name->value, $slug), new CategoryDeleted($store->name->value, $slug)];

        $this->guard->run($store, $category, $actor, 'delete', $events[0], $events[1], static function (DatabaseDriver $database) use ($category, $store): void {
            $connection = $database->connectionName();
            $id = SettingCategory::idOf($connection, $store->name, $category);

            // Unlinked here as well as by the foreign keys, which SQLite may not enforce.
            SettingCategory::on($connection)->where('parent_id', $id)->update(['parent_id' => null]);
            $database->unlinkCategory($store->name, $id);
            SettingCategory::on($connection)->whereKey($id)->delete();
        });
    }
}
