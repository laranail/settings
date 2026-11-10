<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/**
 * The key prefix of a typed settings class: property `fromAddress` in group `mail` is the key
 * `mail.from_address`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Group
{
    public function __construct(
        public string $prefix,
    ) {}
}
