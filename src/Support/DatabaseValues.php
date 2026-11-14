<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

/**
 * Normalises column values across database drivers.
 */
final readonly class DatabaseValues
{
    /** Drivers return bool, int or a numeric string; anything else is false. */
    public static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}
