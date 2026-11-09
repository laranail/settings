<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Carbon\CarbonImmutable;

/**
 * One stored value as a layer holds it.
 *
 * `payload` is the encoded value (JSON, or ciphertext of the JSON when `encrypted`). A null
 * payload means "no value on this row"; a stored null is the JSON string `null`, so the two
 * never collapse into one.
 *
 * `group`, `category` (a category slug) and `public` are metadata copied from the definition
 * when the record is written; file layers ignore them, the database layer stores them.
 */
final readonly class Record
{
    public function __construct(
        public Scope $scope,
        public Key $key,
        public Locale $locale,
        public ?string $payload,
        public ?string $type = null,
        public bool $encrypted = false,
        public bool $locked = false,
        public int $version = 1,
        public ?CarbonImmutable $writtenAt = null,
        public ?string $group = null,
        public ?string $category = null,
        public bool $public = false,
    ) {}

    public static function identityOf(Scope $scope, Key $key, Locale $locale): string
    {
        return $scope->value . '|' . $key->value . '|' . $locale->value;
    }

    public function hasValue(): bool
    {
        return $this->payload !== null;
    }

    public function identity(): string
    {
        return self::identityOf($this->scope, $this->key, $this->locale);
    }

    public function withLocked(bool $locked): self
    {
        return new self($this->scope, $this->key, $this->locale, $this->payload, $this->type, $this->encrypted, $locked, $this->version, $this->writtenAt, $this->group, $this->category, $this->public);
    }
}
