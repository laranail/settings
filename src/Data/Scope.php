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

    /** The owner's scope, or `global` without one. */
    public static function of(?OwnerRef $owner): self
    {
        return $owner instanceof OwnerRef ? self::forOwner($owner) : self::global();
    }

    /**
     * Read a stored scope (`global` or `<alias>:<id>`). Null when it has no `:`, so the caller
     * reports the malformed row in its own words; an invalid alias or id still raises
     * InvalidIdentifier through {@see OwnerRef}.
     */
    public static function fromString(string $value): ?self
    {
        if ($value === self::GLOBAL) {
            return self::global();
        }

        $parts = explode(':', $value, 2);

        return count($parts) === 2 ? self::forOwner(new OwnerRef($parts[0], $parts[1])) : null;
    }

    /** The owner this scope belongs to; null for `global`. */
    public function owner(): ?OwnerRef
    {
        if ($this->isGlobal()) {
            return null;
        }

        [$alias, $id] = explode(':', $this->value, 2);

        return new OwnerRef($alias, $id);
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
