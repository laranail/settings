<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Floats; integers and numeric strings are widened. */
final class FloatCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null || is_float($value)) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw new InvalidSettingValue(sprintf('Setting [%s] expects a number.', $key));
    }

    public function fromStorage(mixed $stored): mixed
    {
        return is_int($stored) ? (float) $stored : $stored;
    }
}
