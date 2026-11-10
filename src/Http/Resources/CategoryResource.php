<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Resources;

use Override;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Category;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Attributes\PreserveKeys;

/**
 * A category as the API returns it, by slug (its numeric id is internal, G-42).
 *
 * @property Category $resource
 */
#[PreserveKeys]
final class CategoryResource extends JsonResource
{
    /** Always wrapped in `data`, whatever the application sets for its own resources. */
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(Request $request): array
    {
        $category = $this->resource;

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
