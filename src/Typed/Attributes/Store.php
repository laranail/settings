<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/**
 * The store a typed settings class reads and writes (default: the handle's store).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Store
{
    public function __construct(
        public string $name,
    ) {}
}
