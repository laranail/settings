<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

/**
 * One category of one store, as stored. `parent` is the parent's slug (categories nest, D-11).
 */
final readonly class Category
{
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
    ) {}
}
