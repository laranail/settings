<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Booleans; 1/0 and the strings "true", "false", "1", "0" are accepted. */
final class BooleanCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        return match (true) {
            $value === 1, $value === '1', $value === 'true'  => true,
            $value === 0, $value === '0', $value === 'false' => false,
            default                                          => throw new InvalidSettingValue(sprintf('Setting [%s] expects a boolean.', $key)),
        };
    }

    public function fromStorage(mixed $stored): mixed
    {
        return $stored;
    }
}
