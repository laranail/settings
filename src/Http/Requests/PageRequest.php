<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;
use Simtabi\Laranail\Settings\Data\Http\PageInput;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;
use Simtabi\Laranail\Settings\Http\Requests\Concerns\ReadsAddress;

/** A list request: `?limit=`, `?cursor=` and the list's filters. */
final class PageRequest extends FormRequest
{
    use ReadsAddress;

    /** The largest page a listing serves, and the bound `http.page_size` is clamped to. */
    public const int MAX_LIMIT = 200;

    private const array FILTERS = ['store', 'key', 'scope', 'status', 'action', 'outcome', 'locale', 'since', 'until'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'limit'  => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
            'cursor' => ['nullable', 'string', 'max:255'],
            'since'  => ['nullable', 'date'],
            'until'  => ['nullable', 'date'],
        ] + array_fill_keys(array_diff(self::FILTERS, ['since', 'until']), ['nullable', 'string', 'max:191']);
    }

    /** @param ?int $defaultLimit the page size without `?limit=`; null reads `http.page_size` */
    public function toData(?int $defaultLimit = null): PageInput
    {
        $defaultLimit ??= $this->configuredPageSize();
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

    /** `http.page_size`, clamped to 1 to MAX_LIMIT (50 when unset). */
    private function configuredPageSize(): int
    {
        $size = $this->container->make(Repository::class)->get('laranail.settings.http.page_size', 50);

        return max(1, min(self::MAX_LIMIT, is_numeric($size) ? (int) $size : 50));
    }
}
