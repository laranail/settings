<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

it('keeps a stored null distinct from no value', function (): void {
    $encoder = new ValueEncoder;
    $key = new Key('a');

    expect($encoder->encode($key, null))->toBe('null')
        ->and($encoder->decode($key, 'null'))->toBeNull()
        ->and($encoder->encode($key, 1.0))->toBe('1.0')
        ->and($encoder->decode($key, '{"a":[1,2]}'))->toBe(['a' => [1, 2]])
        ->and(new Record(Scope::global(), $key, Locale::none(), 'null')->hasValue())->toBeTrue()
        ->and(new Record(Scope::global(), $key, Locale::none(), null)->hasValue())->toBeFalse();

    expect(fn (): string => $encoder->encode($key, NAN))->toThrow(InvalidSettingValue::class, 'not JSON-encodable')
        ->and(fn (): mixed => $encoder->decode($key, '{bad'))->toThrow(InvalidSettingValue::class, 'corrupt');
});

it('reports a lookup as found, from a default, or missing', function (): void {
    $found = Lookup::found(null, 'runtime', Scope::global());

    expect($found->isFound())->toBeTrue()
        ->and($found->value())->toBeNull()
        ->and($found->valueOr('x'))->toBeNull()
        ->and($found->layer())->toBe('runtime')
        ->and($found->scope()?->value)->toBe('global')
        ->and(Lookup::fromDefault(3)->value())->toBe(3)
        ->and(Lookup::fromDefault(3)->layer())->toBe('default')
        ->and(Lookup::missing()->isFound())->toBeFalse()
        ->and(Lookup::missing()->valueOr('x'))->toBe('x')
        ->and(fn (): mixed => Lookup::missing()->value())->toThrow(LogicException::class);
});

it('indexes a record set by scope, key and locale', function (): void {
    $record = new Record(Scope::global(), new Key('a'), new Locale('fr'), '1');
    $set = new RecordSet([$record]);

    expect($set->find(Scope::global(), new Key('a'), new Locale('fr')))->toBe($record)
        ->and($set->find(Scope::global(), new Key('a'), Locale::none()))->toBeNull()
        ->and($set->all())->toBe([$record])
        ->and($set)->toHaveCount(1)
        ->and($record->withLocked(true)->locked)->toBeTrue();
});

it('casts each built-in type to storage and back', function (string $type, mixed $input, mixed $stored, mixed $read): void {
    $caster = (new CasterRegistry)->get($type);
    $key = new Key('a');

    expect($caster->toStorage($key, $input))->toBe($stored)
        ->and($caster->fromStorage($stored))->toEqual($read)
        ->and($caster->toStorage($key, null))->toBeNull();
})->with([
    'string'     => ['string', 'x', 'x', 'x'],
    'stringable' => ['string', new class implements Stringable
    {
        public function __toString(): string
        {
            return 'y';
        }
    }, 'y', 'y'],
    'int'             => ['int', 5, 5, 5],
    'int string'      => ['int', '-12', -12, -12],
    'float'           => ['float', 1.5, 1.5, 1.5],
    'float from int'  => ['float', 2, 2.0, 2.0],
    'float string'    => ['float', '2.5', 2.5, 2.5],
    'bool'            => ['bool', false, false, false],
    'bool one'        => ['bool', 1, true, true],
    'bool zero'       => ['bool', '0', false, false],
    'bool true word'  => ['bool', 'true', true, true],
    'bool false word' => ['bool', 'false', false, false],
    'array'           => ['array', ['a' => 1], ['a' => 1], ['a' => 1]],
    'datetime'        => ['datetime', '2026-01-02T03:04:05+00:00', '2026-01-02T03:04:05+00:00', CarbonImmutable::parse('2026-01-02T03:04:05+00:00')],
]);

it('widens a stored integer to float when reading a float setting', function (): void {
    expect((new CasterRegistry)->get('float')->fromStorage(3))->toBe(3.0);
});

it('stores date-time objects as ISO-8601', function (): void {
    expect((new CasterRegistry)->get('datetime')->toStorage(new Key('a'), new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'))))
        ->toBe('2026-01-02T03:04:05+00:00');
});

it('rejects values a caster cannot represent', function (string $type, mixed $input): void {
    expect(fn (): mixed => (new CasterRegistry)->get($type)->toStorage(new Key('a'), $input))->toThrow(InvalidSettingValue::class);
})->with([
    ['string', 5], ['int', '1.5'], ['int', 'x'], ['float', 'x'], ['bool', 'yes'], ['bool', 2], ['array', 'x'],
    ['datetime', 5], ['datetime', 'not a date'],
]);

it('registers casters by alias and refuses duplicates and bad aliases', function (): void {
    $registry = new CasterRegistry;

    expect($registry->has('string'))->toBeTrue()
        ->and($registry->has('money'))->toBeFalse()
        ->and(fn (): Caster => $registry->get('money'))->toThrow(InvalidDefinition::class)
        ->and(fn () => $registry->register('string', $registry->get('string')))->toThrow(InvalidDefinition::class)
        ->and(fn () => $registry->register('Bad Alias', $registry->get('string')))->toThrow(InvalidDefinition::class);
});
