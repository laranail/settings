<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Events\SettingSaving;
use Simtabi\Laranail\Settings\Events\SettingForgotten;
use Simtabi\Laranail\Settings\Events\SettingForgetting;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;

it('announces writes with identifiers only, never the value', function (): void {
    Event::fake();

    settings()->set('mail.password', TestCredentials::secret('first'));
    settings()->set('mail.password', TestCredentials::secret('second'));
    settings()->forget('mail.password');

    Event::assertDispatched(SettingSaving::class);
    Event::assertDispatched(SettingSaved::class, static fn (SettingSaved $event): bool => $event->key === 'mail.password'
        && $event->store === 'settings' && $event->scope === 'global' && $event->version === 2);
    Event::assertDispatched(SettingForgetting::class);
    Event::assertDispatched(SettingForgotten::class, static fn (SettingForgotten $event): bool => $event->version === 2);

    foreach ([SettingSaving::class, SettingSaved::class, SettingForgetting::class, SettingForgotten::class] as $class) {
        expect(array_keys(get_class_vars($class)))->toBe(['store', 'scope', 'key', 'locale', 'version']);
    }
});

it('does not announce forgetting a key that has no value', function (): void {
    Event::fake();

    settings()->forget('never.set');

    Event::assertNotDispatched(SettingForgetting::class);
});
