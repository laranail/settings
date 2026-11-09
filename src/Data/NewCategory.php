<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * The input for creating a category: slug and title, optionally a parent (by slug), a
 * description, an icon name and a sort position.
 */
final readonly class NewCategory
{
    public CategorySlug $slug;

    public ?CategorySlug $parent;

    public function __construct(
        string $slug,
        public string $title,
        ?string $parent = null,
        public ?string $description = null,
        public ?string $icon = null,
        public int $sortOrder = 0,
    ) {
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new InvalidIdentifier(sprintf('Category [%s] needs a title of 1-255 characters.', $slug));
        }

        if ($sortOrder < 0) {
            throw new InvalidIdentifier(sprintf('Category [%s]: the sort order cannot be negative.', $slug));
        }

        $this->slug = new CategorySlug($slug);
        $this->parent = $parent === null ? null : new CategorySlug($parent);
    }
}
