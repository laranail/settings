<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\CategoryRenamed;
use Simtabi\Laranail\Settings\Models\SettingCategory;

/** Give a category a new title; its slug stays. */
final readonly class RenameCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, string $slug, string $title, ?Actor $actor = null): void
    {
        $input = new NewCategory($slug, $title);
        $events = [new CategorySaving($store->name->value, $slug), new CategorySaved($store->name->value, $slug)];

        $this->guard->run($store, $input->slug, $actor, 'rename', $events[0], $events[1], static function (DatabaseDriver $database) use ($store, $input): void {
            $connection = $database->connectionName();
            $category = SettingCategory::on($connection)->findOrFail(SettingCategory::idOf($connection, $store->name, $input->slug));

            // The new title replaces the one for any locale; the other locales keep theirs.
            $category->update(['title' => Translations::of($input->title, Translations::texts($category->title))]);
        }, [new CategoryRenamed($store->name->value, $slug)]);
    }
}
