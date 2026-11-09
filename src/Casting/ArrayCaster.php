<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Arrays of JSON-encodable values. */
final class ArrayCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        throw new InvalidSettingValue(sprintf('Setting [%s] expects an array.', $key));
    }

    public function fromStorage(mixed $stored): mixed
    {
        return $stored;
    }
}
