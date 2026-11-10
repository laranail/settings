<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /stores/{store}/definitions/{key}`: a definition's attributes, as `definitions.<key>`
 * would hold them.
 */
final class SaveDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['attributes' => ['present', 'array']];
    }

    /** @return array<array-key, mixed> */
    public function definition(): array
    {
        return $this->array('attributes');
    }
}
