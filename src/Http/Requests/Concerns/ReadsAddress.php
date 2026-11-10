<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Requests\Concerns;

use Simtabi\Laranail\Settings\Data\Http\AddressInput;

/** Reads the store, key, owner and `?locale=` a setting request addresses. */
trait ReadsAddress
{
    /** @return array<string, list<string>> */
    protected function addressRules(): array
    {
        return ['locale' => ['nullable', 'string', 'max:16']];
    }

    protected function address(): AddressInput
    {
        $text = fn (string $name): ?string => is_string($this->route($name)) ? $this->route($name) : null;
        $locale = $this->query('locale');

        return new AddressInput(
            $text('store') ?? '',
            $text('key') ?? '',
            $text('alias'),
            $text('id'),
            is_string($locale) ? $locale : '',
        );
    }
}
