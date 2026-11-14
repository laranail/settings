<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use LogicException;

/** The result of a read: found (the value may be null) or missing. */
final readonly class Lookup
{
    private function __construct(
        private bool $found,
        private mixed $value,
        private ?string $layer,
        private ?Scope $scope,
        private bool $redacted = false,
        private bool $encrypted = false,
    ) {}

    /** @param bool $encrypted whether the value was stored encrypted, which makes it sensitive whatever its definition says */
    public static function found(mixed $value, string $layer, Scope $scope, bool $encrypted = false): self
    {
        return new self(true, $value, $layer, $scope, encrypted: $encrypted);
    }

    public static function fromDefault(mixed $value): self
    {
        return new self(true, $value, 'default', null);
    }

    public static function missing(): self
    {
        return new self(false, null, null, null);
    }

    /** The same answer with its value withheld; presence, layer and scope stay visible. */
    public function redacted(): self
    {
        return new self($this->found, null, $this->layer, $this->scope, redacted: true, encrypted: $this->encrypted);
    }

    /** Whether the value was stored encrypted: a secret, even when no definition says so any more. */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function isRedacted(): bool
    {
        return $this->redacted;
    }

    public function isFound(): bool
    {
        return $this->found;
    }

    /** @throws LogicException when nothing was found; check {@see isFound()} or use {@see valueOr()} */
    public function value(): mixed
    {
        if (! $this->found) {
            throw new LogicException('The setting was not found; call isFound() or valueOr() first.');
        }

        if ($this->redacted) {
            throw new LogicException('The value is redacted: the actor may not view this setting.');
        }

        return $this->value;
    }

    public function valueOr(mixed $default): mixed
    {
        return $this->found && ! $this->redacted ? $this->value : $default;
    }

    /** The layer that answered (`default` for a definition default), or null when missing. */
    public function layer(): ?string
    {
        return $this->layer;
    }

    /** The scope that answered, or null when missing or answered by a definition default. */
    public function scope(): ?Scope
    {
        return $this->scope;
    }
}
