<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Transfer;

use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/** One category in an export, identified by slug; its parent by slug too (G-42). */
final readonly class ExportedCategory
{
    /** @param array<string, string> $titles locale => title (json translations mode) */
    public function __construct(
        public CategorySlug $slug,
        public string $title,
        public ?CategorySlug $parent = null,
        public ?string $description = null,
        public ?string $icon = null,
        public int $sortOrder = 0,
        public bool $system = false,
        public array $titles = [],
    ) {}

    public static function of(Category $category): self
    {
        return new self($category->slug, $category->title, $category->parent, $category->description, $category->icon, $category->sortOrder, $category->system, $category->titles);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidImport
     */
    public static function fromArray(array $data, int|string $index): self
    {
        try {
            $titles = is_array($data['titles'] ?? null) ? array_filter($data['titles'], is_string(...)) : [];

            return new self(
                new CategorySlug(is_string($data['slug'] ?? null) ? $data['slug'] : ''),
                is_string($data['title'] ?? null) ? $data['title'] : '',
                is_string($data['parent'] ?? null) ? new CategorySlug($data['parent']) : null,
                is_string($data['description'] ?? null) ? $data['description'] : null,
                is_string($data['icon'] ?? null) ? $data['icon'] : null,
                is_int($data['sort_order'] ?? null) ? $data['sort_order'] : 0,
                ($data['system'] ?? false) === true,
                array_combine(array_map(strval(...), array_keys($titles)), array_values($titles)),
            );
        } catch (SettingsException $exception) {
            throw new InvalidImport(sprintf('categories.%s: %s', $index, $exception->getMessage()), 0, $exception);
        }
    }

    /** The category to create on import; `system` only when the import is trusted (G-10). */
    public function toNewCategory(bool $trusted): NewCategory
    {
        return new NewCategory($this->slug->value, $this->title === '' ? $this->slug->value : $this->title, $this->parent?->value, $this->description, $this->icon, $this->sortOrder, $this->titles, $trusted && $this->system);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'slug'        => $this->slug->value,
            'title'       => $this->title,
            'titles'      => $this->titles === [] ? null : $this->titles,
            'parent'      => $this->parent?->value,
            'description' => $this->description,
            'icon'        => $this->icon,
            'sort_order'  => $this->sortOrder,
            'system'      => $this->system ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
