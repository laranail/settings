<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Enums\ChangeOperation;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;

/**
 * `POST /stores/{store}/changes`: propose a change to one setting, for the global value or an
 * owner (`owner.alias`, `owner.id`), as a `set` (the default), `forget`, `lock` or `unlock`.
 */
final class ProposeChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'store'       => ['nullable', 'string', 'max:64'],
            'key'         => ['required', 'string', 'max:191'],
            'value'       => ['nullable'],
            'owner'       => ['nullable', 'array'],
            'owner.alias' => ['required_with:owner', 'string', 'max:64'],
            'owner.id'    => ['required_with:owner', 'string', 'max:36'],
            'locale'      => ['nullable', 'string', 'max:16'],
            'operation'   => ['nullable', 'string', 'in:set,forget,lock,unlock'],
            'reason'      => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The setting the change is for, as the URL and the body name it. */
    public function toData(): AddressInput
    {
        $alias = $this->input('owner.alias');
        $id = $this->input('owner.id');

        return new AddressInput(
            (string) $this->route('store'),
            $this->string('key')->value(),
            is_string($alias) ? $alias : null,
            is_string($id) ? $id : null,
            $this->string('locale')->value(),
        );
    }

    public function operation(): ChangeOperation
    {
        return ChangeOperation::tryFrom($this->string('operation', 'set')->value()) ?? ChangeOperation::Set;
    }

    public function reason(): ?string
    {
        return $this->filled('reason') ? $this->string('reason')->value() : null;
    }
}
