<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Stringable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Strings, and Stringable values stored as their string. */
final class StringCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidSettingValue(sprintf('Setting [%s] expects a string.', $key));
    }

    public function fromStorage(mixed $stored): mixed
    {
        return $stored;
    }
}
