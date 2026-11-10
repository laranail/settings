<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /stores/{store}/categories/{slug}`. A null `parent` moves the category to the top level;
 * omitting it leaves the parent unchanged.
 */
final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title'  => ['nullable', 'string', 'max:255'],
            'parent' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function title(): ?string
    {
        return $this->filled('title') ? $this->string('title')->value() : null;
    }

    /** Whether the body names `parent` at all. */
    public function movesParent(): bool
    {
        return $this->has('parent');
    }

    public function parent(): ?string
    {
        return $this->filled('parent') ? $this->string('parent')->value() : null;
    }
}
