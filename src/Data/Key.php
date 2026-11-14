<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Stringable;
use Simtabi\Laranail\Settings\Exceptions\InvalidKey;

/**
 * A setting key: lower-case dot notation of [a-z0-9_] segments, at most 191 characters. Hyphens
 * are not allowed so the key to slug mapping (`.` to `-`) stays one-to-one.
 */
final readonly class Key implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $normalised = strtolower($value);

        if (strlen($normalised) > 191 || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $normalised) !== 1) {
            throw new InvalidKey(sprintf('Invalid setting key [%s]: use dot notation of [a-z0-9_] segments, at most 191 characters.', $value));
        }

        $this->value = $normalised;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /** The first segment, used as the default group. */
    public function group(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    /** The definition slug: dots become hyphens. */
    public function slug(): string
    {
        return str_replace('.', '-', $this->value);
    }
}
