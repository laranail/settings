<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Support;

use Illuminate\Contracts\Config\Repository;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Typed reads of configuration values. The lenient readers fall back to the default when a value
 * has the wrong type (the provider's options, where a default is always safe); the strict one
 * raises InvalidConfiguration naming the config path (lists the package validates).
 */
final readonly class ConfigValues
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * A list of strings, or InvalidConfiguration naming `$path`.
     *
     * @return list<string>
     */
    public static function listOfStrings(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw new InvalidConfiguration($path . ': a list of strings is required.');
        }

        $strings = array_values(array_filter($value, is_string(...)));

        if (count($strings) !== count($value)) {
            throw new InvalidConfiguration($path . ': a list of strings is required.');
        }

        return $strings;
    }

    public function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /** An integer, or a string of digits; anything else is the default. */
    public function integer(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }

    /** @return array<array-key, mixed> the array at `$key`, or [] */
    public function array(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return list<string> the strings of the array at `$key` (other entries dropped) */
    public function strings(string $key): array
    {
        return array_values(array_filter($this->array($key), is_string(...)));
    }
}
