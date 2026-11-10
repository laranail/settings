<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/** The exact key a property maps to, instead of its group and snake-cased name. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Key
{
    public function __construct(
        public string $key,
    ) {}
}
