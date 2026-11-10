<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Database;

use JsonException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Exceptions\CategoryCycle;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\UnknownCategory;
use Simtabi\Laranail\Settings\Exceptions\CategoryStoreMismatch;

/**
 * The categories of one store, in the categories table on the store's connection. Every lookup is
 * by (store, slug), so a store can never reach another store's category by accident; naming one
 * that exists only in another store raises {@see CategoryStoreMismatch} (G-33).
 *
 * The recorded schema shape decides the rest: generated uuid / ulid keys (D-5), titles as
 * per-locale JSON (D-8), and soft deletes (`categories.soft_deletes`), under which a deleted
 * category is trashed and creating its slug again restores that row, so `unique(store, slug)`
 * never collides.
 */
final readonly class CategoryRepository
{
    public function __construct(
        private DatabaseDriver $database,
        private StoreName $store,
    ) {}

    /** @throws UnknownCategory */
    public function get(CategorySlug $slug): Category
    {
        foreach ($this->all() as $category) {
            if ($category->slug->value === $slug->value) {
                return $category;
            }
        }

        throw $this->unknown($slug);
    }

    /** @return list<Category> the store's categories, by sort order then slug */
    public function all(): array
    {
        $rows = $this->database->attempt($this->store, fn (): array => $this->query()
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->get(['id', 'parent_id', 'slug', 'title', 'description', 'icon', 'sort_order', 'is_system'])
            ->map(static fn (object $row): array => (array) $row)
            ->all());

        $slugs = [];

        foreach ($rows as $row) {
            $slugs[$this->id($row['id'] ?? null)] = $this->string($row['slug'] ?? null);
        }

        return array_values(array_map(fn (array $row): Category => new Category(
            id: $this->id($row['id'] ?? null),
            store: $this->store,
            slug: new CategorySlug($this->string($row['slug'] ?? null)),
            title: $this->text($this->string($row['title'] ?? null)),
            parent: isset($row['parent_id'], $slugs[$this->id($row['parent_id'])]) ? new CategorySlug($slugs[$this->id($row['parent_id'])]) : null,
            description: is_string($row['description'] ?? null) ? $this->text($row['description']) : null,
            icon: is_string($row['icon'] ?? null) ? $row['icon'] : null,
            sortOrder: is_numeric($row['sort_order'] ?? null) ? (int) $row['sort_order'] : 0,
            system: in_array($row['is_system'] ?? false, [true, 1, '1'], true),
            titles: $this->texts($this->string($row['title'] ?? null)),
        ), $rows));
    }

    /**
     * @throws CategoryStoreMismatch when the parent belongs to another store
     * @throws UnknownCategory when the parent does not exist
     */
    public function create(NewCategory $category): Category
    {
        $parent = $category->parent instanceof CategorySlug ? $this->idOf($category->parent) : null;
        $now = CarbonImmutable::now();
        $columns = [
            'parent_id'   => $parent,
            'title'       => $this->encode($category->title, $category->translations),
            'description' => $category->description === null ? null : $this->encode($category->description),
            'icon'        => $category->icon,
            'sort_order'  => $category->sortOrder,
            'is_system'   => false,
            'updated_at'  => $now,
        ];

        // A trashed category of the same slug is restored, not duplicated.
        if ($this->softDeletes() && $this->database->attempt($this->store, fn (): int => $this->table()
            ->where('store', $this->store->value)
            ->where('slug', $category->slug->value)
            ->whereNotNull('deleted_at')
            ->update($columns + ['deleted_at' => null, 'meta' => null])) > 0) {
            return $this->get($category->slug);
        }

        $this->database->attempt($this->store, fn (): bool => $this->table()->insert($columns + DatabaseDriver::newId($this->database->shape()->ids) + [
            'store'      => $this->store->value,
            'slug'       => $category->slug->value,
            'created_at' => $now,
        ]));

        return $this->get($category->slug);
    }

    /** @throws UnknownCategory */
    public function rename(CategorySlug $slug, string $title): void
    {
        $this->update($slug, ['title' => $this->encode($title, $this->get($slug)->titles)]);
    }

    /**
     * Put a category under another (or at the top, with null). Moving it under itself or one
     * of its descendants is refused.
     *
     * @throws CategoryCycle
     * @throws CategoryStoreMismatch
     * @throws UnknownCategory
     */
    public function move(CategorySlug $slug, ?CategorySlug $parent): void
    {
        $id = $this->idOf($slug);
        $parentId = $parent instanceof CategorySlug ? $this->idOf($parent) : null;

        if ($parentId !== null && in_array((string) $id, array_map(strval(...), [$parentId, ...$this->ancestors($parentId)]), true)) {
            throw new CategoryCycle(sprintf('Category [%s] in store [%s] cannot move under [%s]: that is itself or one of its descendants.', $slug, $this->store, $parent));
        }

        $this->update($slug, ['parent_id' => $parentId]);
    }

    /**
     * Delete a category. Its children move to the top level and settings in it lose their
     * category, explicitly as well as through the foreign keys (SQLite may not enforce them).
     * With soft deletes the row is trashed rather than removed.
     *
     * @throws UnknownCategory
     */
    public function delete(CategorySlug $slug, string $valuesTable): void
    {
        $id = $this->idOf($slug);

        $this->database->attempt($this->store, function () use ($id, $valuesTable): void {
            $this->query()->where('parent_id', $id)->update(['parent_id' => null]);
            $this->database->connection()->table($valuesTable)
                ->where('category_id', $id)
                ->where('category_store', $this->store->value)
                ->update(['category_id' => null, 'category_store' => null]);
            $this->softDeletes()
                ? $this->query()->where('id', $id)->update(['deleted_at' => CarbonImmutable::now(), 'parent_id' => null])
                : $this->query()->where('id', $id)->delete();
        });
    }

    private function id(mixed $value): int|string
    {
        return is_int($value) || is_string($value) ? $value : throw new DriverFailure('A category row has no usable id.');
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new DriverFailure('A category row is malformed.');
    }

    /** @param array<string, mixed> $columns */
    private function update(CategorySlug $slug, array $columns): void
    {
        $this->idOf($slug);

        $this->database->attempt($this->store, fn (): int => $this->query()
            ->where('slug', $slug->value)
            ->update($columns + ['updated_at' => CarbonImmutable::now()]));
    }

    /** @return list<int|string> the ids above this one, nearest first */
    private function ancestors(int|string $id): array
    {
        $ancestors = [];
        $current = $id;

        // Bounded by the number of categories, so a cycle already in the data cannot loop forever.
        for ($guard = 0; $guard < 10_000; $guard++) {
            $parent = $this->database->attempt($this->store, fn (): mixed => $this->query()->where('id', $current)->value('parent_id'));

            if (! is_int($parent) && ! is_string($parent)) {
                return $ancestors;
            }

            $ancestors[] = $parent;
            $current = $parent;
        }

        throw new CategoryCycle(sprintf('The categories of store [%s] already contain a cycle; repair them in the database.', $this->store));
    }

    /**
     * @throws CategoryStoreMismatch
     * @throws UnknownCategory
     */
    private function idOf(CategorySlug $slug): int|string
    {
        $id = $this->database->attempt($this->store, fn (): mixed => $this->query()->where('slug', $slug->value)->value('id'));

        if (is_int($id) || is_string($id)) {
            return $id;
        }

        throw $this->unknown($slug);
    }

    private function unknown(CategorySlug $slug): UnknownCategory|CategoryStoreMismatch
    {
        $elsewhere = $this->database->attempt($this->store, fn (): mixed => $this->live($this->table())->where('slug', $slug->value)->value('store'));

        return is_string($elsewhere)
            ? new CategoryStoreMismatch(sprintf('Category [%s] belongs to store [%s], not [%s].', $slug, $elsewhere, $this->store))
            : new UnknownCategory(sprintf('Store [%s] has no category [%s].', $this->store, $slug));
    }

    /** This store's live (not trashed) categories. */
    private function query(): Builder
    {
        return $this->live($this->table()->where('store', $this->store->value));
    }

    private function live(Builder $query): Builder
    {
        return $this->softDeletes() ? $query->whereNull('deleted_at') : $query;
    }

    private function softDeletes(): bool
    {
        return $this->database->shape()->categoriesSoftDeletes;
    }

    private function json(): bool
    {
        return $this->database->shape()->translations === TranslationsMode::Json;
    }

    /**
     * A title or description as the column holds it: the text itself, or (json mode) a JSON map
     * of locale to text with `''` for the text that applies to any locale.
     *
     * @param array<string, string> $translations
     */
    private function encode(string $text, array $translations = []): string
    {
        if (! $this->json()) {
            return $text;
        }

        try {
            return json_encode(['' => $text] + $translations, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DriverFailure('A category title could not be encoded.', 0, $exception);
        }
    }

    /** The text for any locale. */
    private function text(string $stored): string
    {
        $texts = $this->texts($stored);

        return $texts[''] ?? (string) reset($texts);
    }

    /** @return array<string, string> locale => text */
    private function texts(string $stored): array
    {
        if (! $this->json()) {
            return ['' => $stored];
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || $decoded === []) {
            return ['' => $stored];
        }

        $texts = [];

        foreach ($decoded as $locale => $text) {
            if (is_string($text)) {
                $texts[(string) $locale] = $text;
            }
        }

        return $texts === [] ? ['' => $stored] : $texts;
    }

    private function table(): Builder
    {
        return $this->database->connection()->table($this->database->names()->categories());
    }
}
