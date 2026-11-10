<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;

/**
 * `PUT /configuration/{stores|layers}/{name}`: a persisted store or layer entry, as the
 * configuration file would hold it.
 */
final class SaveConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['config' => ['required', 'array']];
    }

    public function toData(): ConfigurationEntry
    {
        return new ConfigurationEntry(rtrim((string) $this->route('kind'), 's'), (string) $this->route('name'), $this->array('config'));
    }
}
