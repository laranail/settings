<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

it('builds and reads per-locale title maps', function (): void {
    expect(Translations::of('Mail/É', ['fr' => 'Courrier']))->toBe(['' => 'Mail/É', 'fr' => 'Courrier'])
        ->and(Translations::ofNullable(null))->toBeNull()
        ->and(Translations::ofNullable('Mail'))->toBe(['' => 'Mail'])
        ->and(Translations::text(['' => 'Mail', 'fr' => 'Courrier']))->toBe('Mail')
        ->and(Translations::text(['fr' => 'Courrier', 'de' => 'Post']))->toBe('Courrier')
        ->and(Translations::texts(['' => 'Mail', 'fr' => 3]))->toBe(['' => 'Mail'])
        ->and(Translations::text('Mail'))->toBe('Mail')
        ->and(Translations::texts('Mail'))->toBe(['' => 'Mail'])
        ->and(Translations::text(['fr' => 3]))->toBeNull()
        ->and(Translations::text(null))->toBeNull();
});

it('encodes JSON with one set of flags and names what failed', function (): void {
    expect(Json::encode(['a/b' => 'É'], 'A thing'))->toBe('{"a/b":"É"}')
        ->and(fn (): string => Json::encode(['x' => NAN], 'A definition'))->toThrow(DriverFailure::class, 'A definition could not be encoded.')
        ->and(Json::strings('["a",1,"b"]'))->toBe(['a', 'b'])
        ->and(Json::strings('{"x":"a"}'))->toBe(['a'])
        ->and(Json::strings('oops'))->toBe([])
        ->and(Json::strings(null))->toBe([]);
});

it('reads boolean columns however the driver returns them', function (mixed $value, bool $expected): void {
    expect(DatabaseValues::boolean($value))->toBe($expected);
})->with([[true, true], [1, true], ['1', true], [false, false], [0, false], ['0', false], [null, false], ['true', false]]);

it('parses stored scopes and names their owner', function (): void {
    $owner = new OwnerRef('user', 42);

    expect(Scope::of(null)->value)->toBe('global')
        ->and(Scope::of($owner)->value)->toBe('user:42')
        ->and(Scope::fromString('global')?->isGlobal())->toBeTrue()
        ->and(Scope::fromString('user:42')?->owner()?->alias)->toBe('user')
        ->and(Scope::fromString('user:42')?->owner()?->id)->toBe('42')
        ->and(Scope::fromString('nocolon'))->toBeNull()
        ->and(Scope::global()->owner())->toBeNull()
        ->and(fn (): ?Scope => Scope::fromString('App\\Models\\User:1'))->toThrow(InvalidIdentifier::class);
});

it('reports a file record with a bad key, locale, date or version as a malformed record', function (array $row): void {
    expect(fn (): mixed => new RecordSerializer()->fromArray(['scope' => 'global', 'key' => 'site.name', ...$row], 'runtime.json'))
        ->toThrow(DriverFailure::class, 'A record in [runtime.json] is malformed');
})->with([
    'key'     => [['key' => 'Not A Key']],
    'locale'  => [['locale' => 'zz-garbage-locale']],
    'date'    => [['written_at' => 'not a date']],
    'version' => [['version' => 0]],
]);
