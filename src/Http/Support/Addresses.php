<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Support;

use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Data\Http\AddressInput;
use Simtabi\Laranail\Settings\Contracts\OwnerResolver;
use Simtabi\Laranail\Settings\Exceptions\UnknownOwner;

/** Turns what a URL names into a {@see SettingAddress}, resolving the owner by alias and id. */
final readonly class Addresses
{
    public function __construct(
        private OwnerResolver $owners,
    ) {}

    /** @throws UnknownOwner */
    public function of(AddressInput $input): SettingAddress
    {
        $owner = $input->alias !== null && $input->id !== null ? $this->owners->resolve($input->alias, $input->id) : null;

        return new SettingAddress($input->store, $input->key, $owner, $input->locale);
    }
}
