<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Stringable;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * A category slug: lower-case letters, digits and hyphens, at most 128 characters, unique within
 * its store.
 */
final readonly class CategorySlug implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $value) !== 1 || strlen($value) > 128) {
            throw new InvalidIdentifier(sprintf('Invalid category slug [%s]: use [a-z0-9] words joined by hyphens, at most 128 characters.', $value));
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
