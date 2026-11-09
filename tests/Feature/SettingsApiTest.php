<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;

it('writes to the runtime file and reads the value back', function (): void {
    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(Storage::disk('local')->exists('laranail/settings/settings.json'))->toBeTrue();
});

it('returns the caller default when nothing is stored', function (): void {
    expect(settings()->get('site.name', 'fallback'))->toBe('fallback')
        ->and(settings()->has('site.name'))->toBeFalse()
        ->and(settings()->lookup('site.name')->isFound())->toBeFalse();
});

it('treats a stored null as an answer rather than as absence', function (): void {
    settings()->set('site.tagline', null);

    expect(settings()->has('site.tagline'))->toBeTrue()
        ->and(settings()->get('site.tagline', 'fallback'))->toBeNull()
        ->and(settings()->lookup('site.tagline')->value())->toBeNull();
});

it('reads shipped defaults, lets a written value override them, and falls back after forget', function (): void {
    shipDefaults(['mail.from.address' => 'hello@example.com']);

    expect(settings()->get('mail.from.address'))->toBe('hello@example.com')
        ->and(settings()->lookup('mail.from.address')->layer())->toBe('shipped');

    settings()->set('mail.from.address', 'ap@acme.test');
    expect(settings()->get('mail.from.address'))->toBe('ap@acme.test')
        ->and(settings()->lookup('mail.from.address')->layer())->toBe('runtime');

    settings()->forget('mail.from.address');
    expect(settings()->get('mail.from.address'))->toBe('hello@example.com');
});

it('lower-cases keys so one key has one value', function (): void {
    settings()->set('Site.Name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('offers typed getters that reject other types and missing values', function (): void {
    settings()->set('limits.seats', 5);
    settings()->set('limits.ratio', 1.5);
    settings()->set('features.beta', true);
    settings()->set('site.name', 'Acme');
    settings()->set('site.tags', ['a', 'b']);

    expect(settings()->integer('limits.seats'))->toBe(5)
        ->and(settings()->float('limits.ratio'))->toBe(1.5)
        ->and(settings()->float('limits.seats'))->toBe(5.0)
        ->and(settings()->boolean('features.beta'))->toBeTrue()
        ->and(settings()->string('site.name'))->toBe('Acme')
        ->and(settings()->array('site.tags'))->toBe(['a', 'b']);

    expect(fn (): string => settings()->string('limits.seats'))->toThrow(TypeMismatch::class, 'is not a string')
        ->and(fn (): int => settings()->integer('site.name'))->toThrow(TypeMismatch::class)
        ->and(fn (): float => settings()->float('site.name'))->toThrow(TypeMismatch::class)
        ->and(fn (): bool => settings()->boolean('site.name'))->toThrow(TypeMismatch::class)
        ->and(fn (): array => settings()->array('site.name'))->toThrow(TypeMismatch::class)
        ->and(fn (): string => settings()->string('missing.key'))->toThrow(TypeMismatch::class, 'has no value');
});

it('explains which layer answered and what it tried', function (): void {
    shipDefaults(['site.name' => 'Shipped']);

    $explanation = settings()->explain('site.name');

    expect($explanation->lookup->layer())->toBe('shipped')
        ->and($explanation->steps)->toBe(['settings / global / (none) / runtime', 'settings / global / (none) / shipped']);
});

it('derives new handles without changing the shared instance', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    $base = settings();

    $scoped = $base->for(new OwnerRef('user', 1));
    $scoped->set('ui.theme', 'dark');

    expect($scoped)->not->toBe($base)
        ->and($base->get('ui.theme'))->toBeNull()
        ->and($scoped->get('ui.theme'))->toBe('dark');
});
