<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use JsonException;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/**
 * Encodes and decodes the package's own JSON columns, with unescaped Unicode and slashes.
 */
final readonly class Json
{
    /**
     * @param array<array-key, mixed> $value
     * @param string $what what is being encoded, for the error message
     *
     * @throws DriverFailure
     */
    public static function encode(array $value, string $what): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DriverFailure($what . ' could not be encoded.', 0, $exception);
        }
    }

    /**
     * A JSON list of strings; [] for null or invalid JSON, and non-string entries dropped.
     *
     * @return list<string>
     */
    public static function strings(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
