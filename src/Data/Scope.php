<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Stringable;

/**
 * Who a value belongs to: `global` (the application) or `<alias>:<id>` for an owner.
 * Built only from {@see OwnerRef} or {@see self::global()}, so it is always well formed.
 */
final readonly class Scope implements Stringable
{
    public const string GLOBAL = 'global';

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function global(): self
    {
        return new self(self::GLOBAL);
    }

    public static function forOwner(OwnerRef $owner): self
    {
        return new self($owner->alias . ':' . $owner->id);
    }

    public function isGlobal(): bool
    {
        return $this->value === self::GLOBAL;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
