<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One stored category. `parent` is the parent's slug; `titles` maps locale to title, with `''`
 * for the title that applies to any locale.
 */
final readonly class Category
{
    /** @param array<string, string> $titles locale => title */
    public function __construct(
        public int|string $id,
        public StoreName $store,
        public CategorySlug $slug,
        public string $title,
        public ?CategorySlug $parent = null,
        public ?string $description = null,
        public ?string $icon = null,
        public int $sortOrder = 0,
        public bool $system = false,
        public array $titles = [],
    ) {}

    /** The title in a locale: that locale, then its language, then the title for any locale. */
    public function titleIn(string $locale): string
    {
        $locale = new Locale($locale)->value;
        $language = explode('_', $locale)[0];

        return $this->titles[$locale] ?? $this->titles[$language] ?? $this->title;
    }
}
