<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\NewCategory;

/** `POST /stores/{store}/categories`. */
final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'slug'        => ['required', 'string', 'max:128'],
            'title'       => ['required', 'string', 'max:255'],
            'parent'      => ['nullable', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'icon'        => ['nullable', 'string', 'max:255'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'titles'      => ['nullable', 'array'],
        ];
    }

    public function toData(): NewCategory
    {
        return new NewCategory(
            $this->string('slug')->value(),
            $this->string('title')->value(),
            $this->filled('parent') ? $this->string('parent')->value() : null,
            $this->filled('description') ? $this->string('description')->value() : null,
            $this->filled('icon') ? $this->string('icon')->value() : null,
            $this->integer('sort_order'),
            $this->array('titles'),
        );
    }
}
