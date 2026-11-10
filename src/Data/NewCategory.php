<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * The input for creating a category: slug and title, optionally a parent (by slug), a
 * description, an icon name, a sort position and, for `translations.mode = json` (D-8), the
 * title per locale. Under `lang` the translations are ignored: the title is plain text or a
 * translation key.
 */
final readonly class NewCategory
{
    public CategorySlug $slug;

    public ?CategorySlug $parent;

    /** @var array<string, string> locale => title, normalised */
    public array $translations;

    /** @param array<array-key, mixed> $translations locale => title (json translations mode) */
    public function __construct(
        string $slug,
        public string $title,
        ?string $parent = null,
        public ?string $description = null,
        public ?string $icon = null,
        public int $sortOrder = 0,
        array $translations = [],
    ) {
        if (trim($title) === '' || mb_strlen($title) > 255) {
            throw new InvalidIdentifier(sprintf('Category [%s] needs a title of 1-255 characters.', $slug));
        }

        if ($sortOrder < 0) {
            throw new InvalidIdentifier(sprintf('Category [%s]: the sort order cannot be negative.', $slug));
        }

        $normalised = [];

        foreach ($translations as $locale => $text) {
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 255) {
                throw new InvalidIdentifier(sprintf('Category [%s] needs a title of 1-255 characters in locale [%s].', $slug, $locale));
            }

            $normalised[new Locale((string) $locale)->value] = $text;
        }

        $this->translations = $normalised;
        $this->slug = new CategorySlug($slug);
        $this->parent = $parent === null ? null : new CategorySlug($parent);
    }
}
