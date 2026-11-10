<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Enums\ExportMode;

/** `POST /stores/{store}/export`. */
final class ExportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['mode' => ['nullable', 'string', 'in:redacted,ciphertext,plaintext']];
    }

    public function mode(): ExportMode
    {
        return ExportMode::tryFrom($this->string('mode', 'redacted')->value()) ?? ExportMode::Redacted;
    }
}
