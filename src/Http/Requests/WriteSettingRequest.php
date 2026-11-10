<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\WriteInput;
use Simtabi\Laranail\Settings\Http\Requests\Concerns\ReadsAddress;

/**
 * A write of one setting (update, forget, lock, unlock): `PUT` sends `{"value": …}`, and every
 * write may send `reason`. `If-Match` makes it conditional on the ETag read.
 */
final class WriteSettingRequest extends FormRequest
{
    use ReadsAddress;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = $this->addressRules() + ['reason' => ['nullable', 'string', 'max:255']];

        if ($this->isMethod('PUT')) {
            $rules['value'] = ['present'];
        }

        return $rules;
    }

    public function toData(): WriteInput
    {
        $reason = $this->input('reason');
        $ifMatch = $this->header('If-Match');

        return new WriteInput(
            $this->address(),
            $this->input('value'),
            is_string($reason) ? $reason : null,
            is_string($ifMatch) && $ifMatch !== '' ? $ifMatch : null,
        );
    }
}
