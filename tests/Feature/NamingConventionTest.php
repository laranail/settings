<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

/**
 * Commands, config keys and publish tags live in flat, host-owned registries, so every name
 * this package registers carries the vendor. Read from the live registries, not the source.
 */
it('registers its install command under the vendor-scoped name', function (): void {
    expect(Artisan::all())->toHaveKey('laranail::settings.install');
});

it('registers no bare settings command', function (): void {
    $ours = array_filter(
        array_keys(Artisan::all()),
        static fn (string $name): bool => str_contains($name, 'settings'),
    );

    expect($ours)->not->toBeEmpty();

    foreach ($ours as $name) {
        expect($name)->toStartWith('laranail::settings.');
    }
});

it('merges its config at the vendor-scoped key and not at a bare one', function (): void {
    expect(config('laranail.settings'))->toBeArray()
        ->and(config('laranail.settings.default'))->toBe('settings')
        ->and(config('settings'))->toBeNull();
});

it('publishes its config under the vendor-scoped tag', function (): void {
    $groups = ServiceProvider::publishableGroups();

    expect($groups)->toContain('laranail::settings-config')
        ->and(array_keys(ServiceProvider::pathsToPublish(SettingsServiceProvider::class, 'laranail::settings-config')))
        ->not->toBeEmpty();
});
