<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

/**
 * Reading column values the way each database returns them. Stateless.
 */
final readonly class DatabaseValues
{
    /** A boolean column: drivers return bool, int or a numeric string; anything else is false. */
    public static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
