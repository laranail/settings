<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionProperty;
use ReflectionNamedType;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Typed\Attributes\Key;
use Simtabi\Laranail\Settings\Typed\Attributes\Type;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Typed\Attributes\Encrypted;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;

/**
 * One public typed property of a typed settings class: the key it maps to, and how its value
 * crosses into storage (an enum as its backing value, a date as an ISO 8601 string) and back.
 * Supported types: string, int, float, bool, array, a backed enum and the immutable date types,
 * each nullable.
 */
final readonly class TypedProperty
{
    public function __construct(
        public ReflectionProperty $property,
        public string $key,
        public string $type,
        public bool $nullable,
        public bool $encrypted,
        public ?string $alias,
    ) {}

    /** @throws InvalidDefinition when the property's type cannot be stored */
    public static function of(ReflectionProperty $property, ?string $group): self
    {
        $type = $property->getType();

        if (! $type instanceof ReflectionNamedType) {
            throw new InvalidDefinition(sprintf('%s::$%s needs a single declared type (union types cannot be mapped to a setting).', $property->getDeclaringClass()->getName(), $property->getName()));
        }

        $name = $type->getName();

        if (! in_array($name, ['string', 'int', 'float', 'bool', 'array'], true) && ! is_subclass_of($name, BackedEnum::class) && ! in_array($name, [DateTimeImmutable::class, CarbonImmutable::class, DateTimeInterface::class], true)) {
            throw new InvalidDefinition(sprintf('%s::$%s has type [%s]; use string, int, float, bool, array, a backed enum or DateTimeImmutable.', $property->getDeclaringClass()->getName(), $property->getName(), $name));
        }

        $key = ($property->getAttributes(Key::class)[0] ?? null)?->newInstance()->key
            ?? ltrim(($group === null ? '' : $group . '.') . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property->getName())), '.');

        return new self(
            $property,
            $key,
            $name,
            $type->allowsNull(),
            $property->getAttributes(Encrypted::class) !== [],
            ($property->getAttributes(Type::class)[0] ?? null)?->newInstance()->alias,
        );
    }

    /** The caster alias a definition of this property takes. */
    public function casterAlias(): ?string
    {
        return $this->alias ?? match ($this->type) {
            'string', 'int', 'float', 'bool', 'array'                                  => $this->type,
            DateTimeImmutable::class, CarbonImmutable::class, DateTimeInterface::class => null,
            default                                                                    => null,
        };
    }

    /** A property value as it is stored: an enum's backing value, a date as ISO 8601. */
    public function toStored(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum        => $value->value,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toIso8601String(),
            default                             => $value,
        };
    }

    /**
     * A stored value as the property holds it.
     *
     * @throws TypeMismatch when it cannot be one
     */
    public function fromStored(mixed $value): mixed
    {
        if ($value === null) {
            return $this->nullable ? null : throw $this->mismatch($value);
        }

        $type = $this->type;

        return match (true) {
            $type === 'string'                       => is_string($value) ? $value : throw $this->mismatch($value),
            $type === 'int'                          => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : throw $this->mismatch($value),
            $type === 'float'                        => is_int($value) || is_float($value) || is_numeric($value) ? (float) $value : throw $this->mismatch($value),
            $type === 'bool'                         => is_bool($value) ? $value : throw $this->mismatch($value),
            $type === 'array'                        => is_array($value) ? $value : throw $this->mismatch($value),
            is_subclass_of($type, BackedEnum::class) => (is_int($value) || is_string($value) ? $type::tryFrom($value) : null) ?? throw $this->mismatch($value),
            $value instanceof DateTimeInterface      => CarbonImmutable::instance($value),
            is_string($value)                        => $type === DateTimeImmutable::class ? new DateTimeImmutable($value) : CarbonImmutable::parse($value),
            default                                  => throw $this->mismatch($value),
        };
    }

    private function mismatch(mixed $value): TypeMismatch
    {
        return new TypeMismatch(sprintf('Setting [%s] holds %s, which %s::$%s (%s%s) cannot take.', $this->key, get_debug_type($value), $this->property->getDeclaringClass()->getShortName(), $this->property->getName(), $this->nullable ? '?' : '', $this->type));
    }
}
