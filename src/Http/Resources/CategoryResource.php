<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Simtabi\Laranail\Settings\Data\Category;

/** A category as the API returns it, by slug (its numeric id is internal, G-42). */
final readonly class CategoryResource
{
    /** @return array<string, mixed> */
    public static function fields(Category $category): array
    {
        return [
            'slug'        => $category->slug->value,
            'store'       => $category->store->value,
            'title'       => $category->title,
            'titles'      => (object) $category->titles,
            'parent'      => $category->parent?->value,
            'description' => $category->description,
            'icon'        => $category->icon,
            'sort_order'  => $category->sortOrder,
            'system'      => $category->system,
        ];
    }
}
