<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Exceptions\InvalidImport;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;

/** `POST /stores/{store}/diff`. */
final class DiffStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['document' => ['required', 'array'], 'apply' => ['nullable', 'boolean']];
    }

    /** @throws InvalidImport */
    public function document(): ExportDocument
    {
        return ExportDocument::fromArray($this->array('document'));
    }
}
