<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\PageInput;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;
use Simtabi\Laranail\Settings\Http\Requests\Concerns\ReadsAddress;

/**
 * A list request: `?limit=` (1-200), `?cursor=` (from the previous page's `meta.next_cursor`) and
 * the list's filters (`store`, `key`, `scope`, `status`, `action`, `outcome`, `locale`, `since`,
 * `until`). Route parameters (store, key, owner) come through `address()`.
 */
final class PageRequest extends FormRequest
{
    use ReadsAddress;

    private const array FILTERS = ['store', 'key', 'scope', 'status', 'action', 'outcome', 'locale', 'since', 'until'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'limit'  => ['nullable', 'integer', 'min:1', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'since'  => ['nullable', 'date'],
            'until'  => ['nullable', 'date'],
        ] + array_fill_keys(array_diff(self::FILTERS, ['since', 'until']), ['nullable', 'string', 'max:191']);
    }

    public function toData(int $defaultLimit = 50): PageInput
    {
        $limit = $this->query('limit');
        $cursor = $this->query('cursor');
        $filters = [];

        foreach (self::FILTERS as $name) {
            $value = $this->query($name);

            if (is_string($value) && $value !== '') {
                $filters[$name] = $value;
            }
        }

        return new PageInput(is_numeric($limit) ? (int) $limit : $defaultLimit, is_string($cursor) && $cursor !== '' ? $cursor : null, $filters);
    }

    public function addressData(): AddressInput
    {
        return $this->address();
    }
}
