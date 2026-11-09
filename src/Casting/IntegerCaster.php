<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Integers; numeric strings without a fraction are accepted. */
final class IntegerCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidSettingValue(sprintf('Setting [%s] expects an integer.', $key));
    }

    public function fromStorage(mixed $stored): mixed
    {
        return $stored;
    }
}
