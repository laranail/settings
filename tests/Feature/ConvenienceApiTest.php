<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Exceptions\InvalidKey;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Services\Resolution\Resolver;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;

use function Simtabi\Laranail\Settings\settings as settingsHelper;

use Simtabi\Laranail\Settings\Contracts\Settings as SettingsContract;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

it('reports a missing key as the opposite of has()', function (): void {
    expect(settings()->missing('site.name'))->toBeTrue();

    settings()->set('site.name', 'Acme');

    expect(settings()->missing('site.name'))->toBeFalse();
});

it('reads many keys at once, with per-key defaults', function (): void {
    settings()->set('site.name', 'Acme');

    expect(settings()->many(['site.name', 'site.tagline' => 'Welcome', 'site.logo']))->toBe([
        'site.name'    => 'Acme',
        'site.tagline' => 'Welcome',
        'site.logo'    => null,
    ]);
});

it('refuses a list entry that is not a key', function (): void {
    settings()->many([42]);
})->throws(InvalidKey::class);

it('writes and forgets many keys, in order', function (): void {
    settings()->setMany(['site.name' => 'Acme', 'site.tagline' => 'Hi']);

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->get('site.tagline'))->toBe('Hi');

    settings()->forgetMany(['site.name', 'site.tagline']);

    expect(settings()->has('site.name'))->toBeFalse()
        ->and(settings()->has('site.tagline'))->toBeFalse();
});

it('remembers a computed value: written once, read back afterwards', function (): void {
    $calls = 0;
    $compute = static function () use (&$calls): string {
        $calls++;

        return 'generated';
    };

    expect(settings()->remember('install.id', $compute))->toBe('generated')
        ->and(settings()->remember('install.id', $compute))->toBe('generated')
        ->and($calls)->toBe(1)
        ->and(settings()->get('install.id'))->toBe('generated');
});

it('lists every visible value in key order, filtered by prefix', function (): void {
    app(DefinitionRegistry::class)->define('site.locale')->withDefault('en')->register();
    settings()->setMany(['site.name' => 'Acme', 'mail.from' => 'a@acme.test', 'sitemap.enabled' => true]);

    expect(settings()->all())->toBe([
        'mail.from'       => 'a@acme.test',
        'site.locale'     => 'en',
        'site.name'       => 'Acme',
        'sitemap.enabled' => true,
    ])->and(settings()->all('site'))->toBe(['site.locale' => 'en', 'site.name' => 'Acme'])
        ->and(settings()->all('site.'))->toBe(['site.locale' => 'en', 'site.name' => 'Acme'])
        ->and(settings()->all('site.name'))->toBe(['site.name' => 'Acme']);
});

it('lists an owner\'s own values', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    settings()->set('site.name', 'Acme');
    settings()->for(new OwnerRef('user', 1))->set('ui.theme', 'dark');

    expect(settings()->for(new OwnerRef('user', 1))->all())->toBe(['ui.theme' => 'dark']);
});

it('leaves out of a listing the keys an actor may not view, and sensitive values it may not read', function (): void {
    app(DefinitionRegistry::class)->define('mail.api_key')->sensitive()->register();
    settings()->setMany(['site.name' => 'Acme', 'billing.plan' => 'pro', 'mail.api_key' => TestCredentials::secret()]);
    Gate::define('laranail-settings.view', static fn (TestUser $user, mixed $subject): bool => ! str_starts_with((string) $subject->key?->value, 'billing.'));
    Gate::define('laranail-settings.view_sensitive', static fn (TestUser $user): bool => false);

    expect(settings()->as(user())->all())->toBe(['site.name' => 'Acme']);
});

it('lists the store\'s definitions for a UI, gated by view', function (): void {
    app(DefinitionRegistry::class)->define('site.name')->withDefault('Acme')->register();
    app(DefinitionRegistry::class)->define('mail.from')->register();
    allowGates(Ability::View);

    expect(array_map(static fn (Definition $definition): string => $definition->key->value, settings()->as(user())->definitions()))
        ->toBe(['mail.from', 'site.name']);

    Gate::define('laranail-settings.view', static fn (TestUser $user): bool => false);

    expect(fn (): array => settings()->as(user())->definitions())->toThrow(AccessDenied::class);
});

it('says how to build a handle that can list definitions', function (): void {
    $manager = new SettingsManager(
        app(StoreRegistry::class),
        app(DefinitionRegistry::class),
        app(Resolver::class),
        app(SettingWriter::class),
    );

    expect(fn (): array => $manager->definitions())->toThrow(LogicException::class, 'definitions listing');
});

it('records many writes, forgets and remembered values in the fake', function (): void {
    app(DefinitionRegistry::class)->define('site.locale')->withDefault('en')->register();
    $fake = Settings::fake();

    Settings::setMany(['site.name' => 'Acme', 'site.tagline' => 'Hi']);
    Settings::forgetMany(['site.tagline']);
    Settings::remember('install.id', static fn (): string => 'generated');
    Settings::remember('install.id', static fn (): string => 'other');

    $fake->assertSaved('site.name', 'Acme');
    $fake->assertForgotten('site.tagline');
    $fake->assertSaved('install.id', 'generated');

    expect(Settings::missing('site.tagline'))->toBeTrue()
        ->and(Settings::many(['site.name']))->toBe(['site.name' => 'Acme'])
        ->and(Settings::all())->toBe(['install.id' => 'generated', 'site.locale' => 'en', 'site.name' => 'Acme'])
        ->and(Settings::definitions())->toHaveCount(1);
});

it('offers a namespaced settings() helper over the contract', function (): void {
    settingsHelper()->set('site.name', 'Acme');

    expect(settingsHelper())->toBeInstanceOf(SettingsContract::class)
        ->and(settingsHelper('site.name'))->toBe('Acme')
        ->and(settingsHelper('site.tagline', 'Welcome'))->toBe('Welcome')
        ->and(function_exists('settings'))->toBeTrue() // the test suite's own global helper, untouched
        ->and(function_exists('Simtabi\Laranail\Settings\settings'))->toBeTrue();
});
