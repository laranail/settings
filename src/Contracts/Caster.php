<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Converts a setting value to a JSON-encodable form for storage, and back.
 */
interface Caster
{
    /** @throws InvalidSettingValue when the value cannot be represented as this type */
    public function toStorage(Key $key, mixed $value): mixed;

    public function fromStorage(mixed $stored): mixed;
}
