<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/** The store a typed settings class uses instead of the handle's. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Store
{
    public function __construct(
        public string $name,
    ) {}
}
