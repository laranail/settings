<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;

it('records writes in memory and asserts on them', function (): void {
    $fake = Settings::fake();

    Settings::set('site.name', 'Acme');
    app(SettingsContract::class)->set('site.locale', 'en');

    $fake->assertSaved('site.name');
    $fake->assertSaved('site.name', 'Acme');
    $fake->assertSaved('site.locale', 'en');
    $fake->assertNotSaved('site.tagline');

    expect(Settings::get('site.name'))->toBe('Acme')
        ->and(Storage::disk('local')->exists('laranail/settings/settings.json'))->toBeFalse();
});

it('fails an assertion for a value that was not saved', function (): void {
    $fake = Settings::fake();
    Settings::set('site.name', 'Acme');

    expect(fn () => $fake->assertSaved('site.name', 'Other'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNotSaved('site.name'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertForgotten('site.name'))->toThrow(AssertionFailedError::class);
});

it('records writes made through derived handles in the same log', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    $fake = Settings::fake();

    Settings::for(new OwnerRef('user', 1))->set('ui.theme', 'dark');
    Settings::for(new OwnerRef('user', 1))->forget('ui.theme');

    $fake->assertSaved('ui.theme', 'dark');
    $fake->assertForgotten('ui.theme');
});
