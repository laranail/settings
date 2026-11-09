<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Stringable;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * A store's logical name: lower-case letters, digits and underscores, starting with a letter,
 * at most 64 characters. It is interpolated into file paths and cache keys, so it is validated
 * here rather than trusted.
 */
final readonly class StoreName implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) !== 1) {
            throw new InvalidIdentifier(sprintf('Invalid store name [%s]: use [a-z][a-z0-9_]*, at most 64 characters.', $value));
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
