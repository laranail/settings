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
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Actions\Categories\MoveCategory;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;
use Simtabi\Laranail\Settings\Actions\Categories\CreateCategory;
use Simtabi\Laranail\Settings\Actions\Categories\DeleteCategory;
use Simtabi\Laranail\Settings\Actions\Categories\RenameCategory;

/**
 * The categories of one store, as the handle's actor. Reads need `view`; changes need
 * `manage_categories` and are audited.
 */
final readonly class CategoryManager
{
    /** The store and actor are set by {@see in()}; without them, the default store as trusted code. */
    public function __construct(
        private CreateCategory $create,
        private RenameCategory $rename,
        private MoveCategory $move,
        private DeleteCategory $delete,
        private AccessGuard $access,
        private StoreRegistry $stores,
        private ?Store $store = null,
        private ?Actor $actor = null,
    ) {}

    /** These categories: the store's, as this actor. */
    public function in(Store $store, ?Actor $actor = null): self
    {
        return new self($this->create, $this->rename, $this->move, $this->delete, $this->access, $this->stores, $store, $actor);
    }

    /**
     * @throws CategoryStoreMismatch
     * @throws UnknownCategory when the parent does not exist
     */
    public function create(NewCategory $category): Category
    {
        return $this->create->handle($this->store(), $category, $this->actor);
    }

    public function rename(string $slug, string $title): void
    {
        $this->rename->handle($this->store(), $slug, $title, $this->actor);
    }

    /**
     * @throws CategoryCycle
     * @throws CategoryStoreMismatch
     */
    public function move(string $slug, ?string $parent): void
    {
        $this->move->handle($this->store(), $slug, $parent, $this->actor);
    }

    public function delete(string $slug): void
    {
        $this->delete->handle($this->store(), $slug, $this->actor);
    }

    /** @throws UnknownCategory */
    public function get(string $slug): Category
    {
        $category = new CategorySlug($slug);

        foreach ($this->list($category->value) as $candidate) {
            if ($candidate->slug->value === $category->value) {
                return $candidate;
            }
        }

        $database = $this->store()->requireDatabase('; categories are kept in the database.');

        // Not in this store: name the store that has it, or report it unknown.
        $database->attempt($this->store()->name, fn (): string => SettingCategory::idOf($database->connectionName(), $this->store()->name, $category));

        throw new UnknownCategory(sprintf('Store [%s] has no category [%s].', $this->store()->name, $category));
    }

    /** @return list<Category> */
    public function all(): array
    {
        return $this->list(null);
    }

    /** @return list<Category> */
    private function list(?string $category): array
    {
        $this->access->authorize($this->actor, Ability::View, new Subject($this->store()->name, category: $category));

        $store = $this->store();
        $database = $store->requireDatabase('; categories are kept in the database.');

        return $database->attempt($store->name, static fn (): array => SettingCategory::listFor($database->connectionName(), $store->name));
    }

    private function store(): Store
    {
        return $this->store ?? $this->stores->default();
    }
}
