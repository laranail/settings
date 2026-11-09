<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Categories;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Database\CategoryRepository;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Actions\Categories\CategoryActions;

/**
 * The categories of one store, for the actor of the handle that returned it
 * (`$settings->store('settings')->as($user)->categories()`). Changes go through the category
 * actions (gated by `manage_categories`, audited); reads need `view`.
 */
final readonly class CategoryManager
{
    public function __construct(
        private Store $store,
        private ?Actor $actor,
        private CategoryActions $actions,
        private AccessGuard $access,
    ) {}

    /**
     * @throws CategoryStoreMismatch
     * @throws UnknownCategory when the parent does not exist
     */
    public function create(NewCategory $category): Category
    {
        return $this->actions->create->handle($this->store, $category, $this->actor);
    }

    public function rename(string $slug, string $title): void
    {
        $this->actions->rename->handle($this->store, $slug, $title, $this->actor);
    }

    /**
     * @throws CategoryCycle
     * @throws CategoryStoreMismatch
     */
    public function move(string $slug, ?string $parent): void
    {
        $this->actions->move->handle($this->store, $slug, $parent, $this->actor);
    }

    public function delete(string $slug): void
    {
        $this->actions->delete->handle($this->store, $slug, $this->actor);
    }

    /** @throws UnknownCategory */
    public function get(string $slug): Category
    {
        $category = new CategorySlug($slug);

        return $this->repository($category->value)->get($category);
    }

    /** @return list<Category> */
    public function all(): array
    {
        return $this->repository(null)->all();
    }

    private function repository(?string $category): CategoryRepository
    {
        $this->access->authorize($this->actor, Ability::View, new Subject($this->store->name, category: $category));

        $database = $this->store->database()
            ?? throw new StoreNotWritable(sprintf('Store [%s] has no active database layer; categories are kept in the database.', $this->store->name));

        return new CategoryRepository($database, $this->store->name);
    }
}
