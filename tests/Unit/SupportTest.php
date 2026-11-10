<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Support\Translations;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Support\DatabaseValues;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Definitions\DatabaseDefinitions;

it('stores and reads titles in both translation modes', function (): void {
    $lang = new Translations(TranslationsMode::Lang);
    $json = new Translations(TranslationsMode::Json);

    expect($lang->store('Mail', ['fr' => 'Courrier']))->toBe('Mail')
        ->and($lang->texts('Mail'))->toBe(['' => 'Mail'])
        ->and($lang->isJson())->toBeFalse()
        ->and($json->store('Mail/É', ['fr' => 'Courrier']))->toBe('{"":"Mail/É","fr":"Courrier"}')
        ->and($json->text('{"":"Mail","fr":"Courrier"}'))->toBe('Mail')
        ->and($json->text('{"fr":"Courrier","de":"Post"}'))->toBe('Courrier')
        ->and($json->texts('{"":"Mail","fr":3}'))->toBe(['' => 'Mail'])
        ->and($json->text('not json'))->toBe('not json')
        ->and($json->text('{"fr":3}'))->toBe('{"fr":3}')
        ->and($json->storeNullable(null))->toBeNull()
        ->and($json->nullableText(null))->toBeNull()
        ->and($json->nullableText('{"":"Mail"}'))->toBe('Mail')
        // The deprecated helper forwards.
        ->and(DatabaseDefinitions::localised('{"":"Mail"}', true))->toBe('Mail')
        ->and(DatabaseDefinitions::localised('{"":"Mail"}', false))->toBe('{"":"Mail"}');
});

it('encodes JSON with one set of flags and names what failed', function (): void {
    expect(Json::encode(['a/b' => 'É'], 'A thing'))->toBe('{"a/b":"É"}')
        ->and(fn () => Json::encode(['x' => NAN], 'A definition'))->toThrow(DriverFailure::class, 'A definition could not be encoded.')
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
        ->and(fn () => Scope::fromString('App\\Models\\User:1'))->toThrow(InvalidIdentifier::class);
});

it('reads configuration leniently, and lists strictly', function (): void {
    $config = new Simtabi\Laranail\Settings\Support\ConfigValues(new Illuminate\Config\Repository([
        'a' => ['name' => 'x', 'n' => '12', 'bad' => 1.5, 'list' => ['x', 2, 'y'], 'scalar' => 'no'],
    ]));

    expect($config->string('a.name', 'd'))->toBe('x')
        ->and($config->string('a.n', 'd'))->toBe('12')
        ->and($config->string('a.bad', 'd'))->toBe('d')
        ->and($config->integer('a.n', 0))->toBe(12)
        ->and($config->integer('a.bad', 7))->toBe(7)
        ->and($config->integer('a.missing', 7))->toBe(7)
        ->and($config->array('a.scalar'))->toBe([])
        ->and($config->strings('a.list'))->toBe(['x', 'y'])
        ->and(Simtabi\Laranail\Settings\Support\ConfigValues::listOfStrings(['x', 'y'], 'p'))->toBe(['x', 'y'])
        ->and(fn () => Simtabi\Laranail\Settings\Support\ConfigValues::listOfStrings(['x', 2], 'p.q'))->toThrow(Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration::class, 'p.q: a list of strings is required.')
        ->and(fn () => Simtabi\Laranail\Settings\Support\ConfigValues::listOfStrings('x', 'p.q'))->toThrow(Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration::class, 'p.q');
});
