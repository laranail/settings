<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use JsonException;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;

/**
 * JSON for the package's own columns (titles, rules, owner lists, audit meta), with one set of
 * flags: unescaped Unicode and slashes, so a stored value reads as it was written. An encoding
 * failure is a DriverFailure naming what was being stored. Stateless.
 */
final readonly class Json
{
    /**
     * @param array<array-key, mixed> $value
     * @param string $what what is being encoded, for the error (`A definition`, `An audit entry's meta`)
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
     * A JSON list of strings, or [] for null, invalid JSON or anything that is not a list of
     * strings (non-string entries are dropped).
     *
     * @return list<string>
     */
    public static function strings(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
