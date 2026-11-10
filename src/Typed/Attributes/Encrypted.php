<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/** Stores the property's setting encrypted. */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Encrypted {}
