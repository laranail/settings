<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Casting;

use BackedEnum;
use Illuminate\Container\Attributes\Singleton;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Casting\EnumCaster;
use Simtabi\Laranail\Settings\Casting\ArrayCaster;
use Simtabi\Laranail\Settings\Casting\FloatCaster;
use Simtabi\Laranail\Settings\Casting\StringCaster;
use Simtabi\Laranail\Settings\Casting\BooleanCaster;
use Simtabi\Laranail\Settings\Casting\IntegerCaster;
use Simtabi\Laranail\Settings\Casting\DateTimeCaster;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;

/**
 * Maps type aliases such as `string`, `bool` or `enum:plan` to casters, never to class names.
 */
#[Singleton]
final class CasterRegistry
{
    /** @var array<string, Caster> */
    private array $casters;

    public function __construct()
    {
        $this->casters = [
            'string'   => new StringCaster,
            'int'      => new IntegerCaster,
            'float'    => new FloatCaster,
            'bool'     => new BooleanCaster,
            'array'    => new ArrayCaster,
            'datetime' => new DateTimeCaster,
        ];
    }

    public function register(string $alias, Caster $caster): void
    {
        if (preg_match('/^[a-z][a-z0-9_:]*$/', $alias) !== 1 || isset($this->casters[$alias])) {
            throw new InvalidDefinition(sprintf('Caster alias [%s] is invalid or already registered.', $alias));
        }

        $this->casters[$alias] = $caster;
    }

    /** @param class-string<BackedEnum> $enum */
    public function registerEnum(string $alias, string $enum): void
    {
        $this->register('enum:' . $alias, new EnumCaster($enum));
    }

    public function has(string $type): bool
    {
        return isset($this->casters[$type]);
    }

    public function get(string $type): Caster
    {
        return $this->casters[$type] ?? throw new InvalidDefinition(sprintf('Unknown setting type [%s]; register a caster for it.', $type));
    }
}
