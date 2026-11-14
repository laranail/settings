<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data\Http;

/** A setting as a URL names it: store, key, the owner's alias and id (or none), and the locale. */
final readonly class AddressInput
{
    public function __construct(
        public string $store,
        public string $key,
        public ?string $alias = null,
        public ?string $id = null,
        public string $locale = '',
    ) {}
}
