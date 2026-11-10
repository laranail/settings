<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\BodyInput;

/**
 * Every other write body, validated by what its route expects: a change proposal (`key`, `value`,
 * `owner`, `locale`, `operation`), a decision (`reason`), a category (`slug`, `title`, `parent`,
 * …), a configuration entry (`config`), a definition (`attributes`), an export (`mode`) or an
 * import (`document`, `apply`).
 */
final class BodyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $name = (string) $this->route()?->getName();

        return match (true) {
            str_ends_with($name, 'changes.store') => [
                'store'       => ['nullable', 'string', 'max:64'],
                'key'         => ['required', 'string', 'max:191'],
                'value'       => ['nullable'],
                'owner'       => ['nullable', 'array'],
                'owner.alias' => ['required_with:owner', 'string', 'max:64'],
                'owner.id'    => ['required_with:owner', 'string', 'max:36'],
                'locale'      => ['nullable', 'string', 'max:16'],
                'operation'   => ['nullable', 'string', 'in:set,forget,lock,unlock'],
                'reason'      => ['nullable', 'string', 'max:255'],
            ],
            str_ends_with($name, 'categories.store') => [
                'slug'        => ['required', 'string', 'max:128'],
                'title'       => ['required', 'string', 'max:255'],
                'parent'      => ['nullable', 'string', 'max:128'],
                'description' => ['nullable', 'string'],
                'icon'        => ['nullable', 'string', 'max:255'],
                'sort_order'  => ['nullable', 'integer', 'min:0'],
                'titles'      => ['nullable', 'array'],
            ],
            str_ends_with($name, 'categories.update') => [
                'title'  => ['nullable', 'string', 'max:255'],
                'parent' => ['nullable', 'string', 'max:128'],
            ],
            str_ends_with($name, 'configuration.update') => ['config' => ['required', 'array']],
            str_ends_with($name, 'definitions.update')   => ['attributes' => ['present', 'array']],
            str_ends_with($name, 'transfer.export')      => ['mode' => ['nullable', 'string', 'in:redacted,ciphertext,plaintext']],
            str_ends_with($name, 'transfer.import'),
            str_ends_with($name, 'transfer.diff') => ['document' => ['required', 'array'], 'apply' => ['nullable', 'boolean']],
            default                               => ['reason' => ['nullable', 'string', 'max:255']],
        };
    }

    public function toData(): BodyInput
    {
        $params = [];

        foreach (['store', 'id', 'slug', 'kind', 'name', 'key'] as $parameter) {
            $value = $this->route($parameter);

            if (is_string($value)) {
                $params[$parameter] = $value;
            }
        }

        /** @var array<string, mixed> $body */
        $body = $this->validated();

        return new BodyInput($params, $body, $this->has('parent'));
    }
}
