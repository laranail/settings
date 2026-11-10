<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A request whose body carries at most a `reason`: reading a change request, deciding one
 * (approve, reject, cancel), and deleting a category, a persisted entry or a definition.
 */
final class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:255']];
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->value() : null;
    }
}
