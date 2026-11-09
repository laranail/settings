<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Events\CategorySaved;
use Simtabi\Laranail\Settings\Events\CategorySaving;
use Simtabi\Laranail\Settings\Database\CategoryRepository;

/**
 * Give a category a new title (its slug, the identifier, stays): needs `manage_categories`.
 */
final readonly class RenameCategory
{
    public function __construct(
        private CategoryGuard $guard,
    ) {}

    public function handle(Store $store, string $slug, string $title, ?Actor $actor = null): void
    {
        // Validates the slug and the title the same way creation does.
        $input = new NewCategory($slug, $title);
        $events = [new CategorySaving($store->name->value, $slug), new CategorySaved($store->name->value, $slug)];

        $this->guard->run($store, $input->slug, $actor, 'rename', $events[0], $events[1], static function (CategoryRepository $categories) use ($input): void {
            $categories->rename($input->slug, $input->title);
        });
    }
}
