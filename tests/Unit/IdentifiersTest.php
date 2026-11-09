<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Exceptions\InvalidKey;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

it('lower-cases keys and derives the group and a one-to-one slug', function (): void {
    $key = new Key('Mail.From_Address');

    expect($key->value)->toBe('mail.from_address')
        ->and($key->group())->toBe('mail')
        ->and($key->slug())->toBe('mail-from_address')
        ->and((string) $key)->toBe('mail.from_address');
});

it('rejects malformed keys', function (string $key): void {
    expect(fn (): Key => new Key($key))->toThrow(InvalidKey::class);
})->with(['', 'a..b', '.a', 'a.', 'a-b', 'a b', '../etc', str_repeat('a', 192)]);

it('accepts a key of exactly 191 characters', function (): void {
    expect((new Key(str_repeat('a', 191)))->value)->toHaveLength(191);
});

it('validates store names', function (): void {
    expect((new StoreName('user_settings'))->value)->toBe('user_settings')
        ->and(new StoreName('a')->equals(new StoreName('a')))->toBeTrue()
        ->and((string) new StoreName('a'))->toBe('a')
        ->and(new StoreName(str_repeat('a', 64)))->toBeInstanceOf(StoreName::class);

    foreach (['', 'User', '1store', 'a-b', '../x', str_repeat('a', 65)] as $bad) {
        expect(fn (): StoreName => new StoreName($bad))->toThrow(InvalidIdentifier::class);
    }
});

it('normalises locales so spellings cannot collide', function (string $input, string $expected): void {
    expect((new Locale($input))->value)->toBe($expected);
})->with([['pt-br', 'pt_BR'], ['PT_BR', 'pt_BR'], ['EN', 'en'], ['fil', 'fil'], ['', '']]);

it('rejects malformed locales and reports whether a locale is set', function (): void {
    foreach (['english', 'e', 'en_USA', 'en-us-x', 'e1'] as $bad) {
        expect(fn (): Locale => new Locale($bad))->toThrow(InvalidIdentifier::class);
    }

    expect(Locale::none()->isLocalised())->toBeFalse()
        ->and(new Locale('fr')->isLocalised())->toBeTrue()
        ->and(new Locale('fr')->equals(new Locale('FR')))->toBeTrue()
        ->and((string) new Locale('fr'))->toBe('fr');
});

it('builds scopes from owner references, never from free text', function (): void {
    $owner = new OwnerRef('user', 42);

    expect($owner->scope()->value)->toBe('user:42')
        ->and($owner->scope()->isGlobal())->toBeFalse()
        ->and(Scope::global()->isGlobal())->toBeTrue()
        ->and(Scope::global()->equals(Scope::global()))->toBeTrue()
        ->and((string) Scope::global())->toBe('global')
        ->and((new OwnerRef('user', '01J9ZK3Y5B8T6W'))->id)->toBe('01J9ZK3Y5B8T6W');
});

it('rejects reserved or malformed owner aliases and ids', function (string $alias, string $id): void {
    expect(fn (): OwnerRef => new OwnerRef($alias, $id))->toThrow(InvalidIdentifier::class);
})->with([['global', '1'], ['User', '1'], ['user', ''], ['user', 'a:b'], ['user', str_repeat('1', 37)], [str_repeat('a', 65), '1']]);

it('lists owners in order and drops missing ids', function (): void {
    $owners = OwnerRef::list(['team' => 7, 'tenant' => null, 'org' => 'x1']);

    expect(array_map(static fn (OwnerRef $owner): string => $owner->scope()->value, $owners))->toBe(['team:7', 'org:x1']);
});
