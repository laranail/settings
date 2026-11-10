<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/**
 * The caster alias `register()` gives the property's definition, instead of the one its PHP type
 * implies (`enum:plan`, `money`).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Type
{
    public function __construct(
        public string $alias,
    ) {}
}
