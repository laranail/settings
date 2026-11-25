<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use BackedEnum;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** A backed enum, stored as its backing value and registered under `enum:<alias>`. */
final readonly class EnumCaster implements Caster
{
    /** @param class-string<BackedEnum> $enum */
    public function __construct(private string $enum) {}

    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enum) {
            return $value->value;
        }

        if ((is_string($value) || is_int($value)) && ($this->enum)::tryFrom($value) !== null) {
            return $value;
        }

        throw new InvalidSettingValue(sprintf('Setting [%s] expects a case of its enum.', $key));
    }

    public function fromStorage(mixed $stored): mixed
    {
        return is_string($stored) || is_int($stored) ? ($this->enum)::tryFrom($stored) : $stored;
    }
}
