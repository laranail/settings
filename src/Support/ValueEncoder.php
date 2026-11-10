<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use JsonException;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Encodes values as JSON for storage. A stored null becomes the string `null`, which keeps it
 * distinct from "no value" (a null payload).
 */
final readonly class ValueEncoder
{
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function encode(Key $key, mixed $value): string
    {
        try {
            return json_encode($value, self::FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidSettingValue(sprintf('Setting [%s] cannot be stored: its value is not JSON-encodable.', $key), $exception->getCode(), previous: $exception);
        }
    }

    public function decode(Key $key, string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, self::FLAGS);
        } catch (JsonException $exception) {
            throw new InvalidSettingValue(sprintf('Setting [%s] has a corrupt stored payload.', $key), $exception->getCode(), previous: $exception);
        }
    }
}
