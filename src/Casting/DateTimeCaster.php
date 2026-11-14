<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Casting;

use Throwable;
use DateTimeInterface;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/** Date-times, stored as ISO-8601 and read back as CarbonImmutable. */
final class DateTimeCaster implements Caster
{
    public function toStorage(Key $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (! is_string($value)) {
            throw new InvalidSettingValue(sprintf('Setting [%s] expects a date-time.', $key));
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable $exception) {
            throw new InvalidSettingValue(sprintf('Setting [%s] expects a date-time.', $key), $exception->getCode(), previous: $exception);
        }
    }

    public function fromStorage(mixed $stored): mixed
    {
        return is_string($stored) ? CarbonImmutable::parse($stored) : $stored;
    }
}
