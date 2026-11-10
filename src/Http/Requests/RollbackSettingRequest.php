<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\WriteInput;
use Simtabi\Laranail\Settings\Http\Requests\Concerns\ReadsAddress;

/**
 * `POST …/settings/{key}/rollback`: the version to return to, and an optional `reason`.
 */
final class RollbackSettingRequest extends FormRequest
{
    use ReadsAddress;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->addressRules() + [
            'reason'  => ['nullable', 'string', 'max:255'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toData(): WriteInput
    {
        return new WriteInput(
            $this->address(),
            reason: $this->filled('reason') ? $this->string('reason')->value() : null,
            toVersion: $this->integer('version'),
        );
    }
}
