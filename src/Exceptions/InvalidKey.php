<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Exceptions;

/** A key is not lower-case dot notation of [a-z0-9_] segments, at most 191 characters. */
final class InvalidKey extends InvalidIdentifier {}
