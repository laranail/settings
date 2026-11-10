<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed\Attributes;

use Attribute;

/**
 * The property's setting is encrypted at rest: `register()` defines it encrypted.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Encrypted {}
