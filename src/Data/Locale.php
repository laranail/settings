<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Stringable;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/** A setting's locale, normalised to `ll` or `ll_CC`; empty means not localised. */
final readonly class Locale implements Stringable
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value === '' ? '' : $this->normalise($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function none(): self
    {
        return new self('');
    }

    public function isLocalised(): bool
    {
        return $this->value !== '';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private function normalise(string $value): string
    {
        $parts = preg_split('/[-_]/', $value);

        if ($parts === false || count($parts) > 2) {
            throw new InvalidIdentifier(sprintf('Invalid locale [%s].', $value));
        }

        $language = strtolower($parts[0]);
        $region = isset($parts[1]) ? strtoupper($parts[1]) : null;

        if (preg_match('/^[a-z]{2,3}$/', $language) !== 1 || ($region !== null && preg_match('/^[A-Z]{2}$/', $region) !== 1)) {
            throw new InvalidIdentifier(sprintf('Invalid locale [%s]: use ll or ll_CC.', $value));
        }

        return $region === null ? $language : $language . '_' . $region;
    }
}
