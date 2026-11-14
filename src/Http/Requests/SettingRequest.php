<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;
use Simtabi\Laranail\Settings\Http\Requests\Concerns\ReadsAddress;

/** A read of one setting. Authorization happens in the action. */
final class SettingRequest extends FormRequest
{
    use ReadsAddress;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->addressRules();
    }

    public function toData(): AddressInput
    {
        return $this->address();
    }
}
