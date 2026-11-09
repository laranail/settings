<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;
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

it('carries actors, reasons and runtime configuration through the fake', function (): void {
    $fake = Settings::fake();
    Gate::define('laranail-settings.update', static fn (TestUser $user): bool => true);
    Auth::guard()->setUser(user(4));

    $fake->as(user())->because('INC-2')->set('site.name', 'Acme');
    $fake->asSystem('cron')->set('site.tagline', 'Hi');
    $fake->asCurrentActor()->set('site.locale', 'en');

    $result = $fake->withConfiguration(
        static fn (RuntimeConfiguration $configuration): mixed => $configuration->requireActor(),
        static fn (): ?string => $fake->configuration()->actorRequirement() === true ? 'required' : null,
    );

    $fake->assertSaved('site.name', 'Acme');
    $fake->assertAudited(AuditAction::Update, AuditOutcome::Allowed, 'site.tagline');

    $reasons = array_map(static fn (AuditEntry $entry): ?string => $entry->reason, $fake->auditEntries());

    expect($fake->as(user())->actor()?->id)->toBe('1')
        ->and($fake->actor())->toBeNull()
        ->and($reasons)->toBe(['INC-2', 'cron', null])
        ->and($fake->auditEntries()[2]->actorId)->toBe('4')
        ->and($result)->toBe('required')
        ->and($fake->configuration()->actorRequirement())->toBeNull();
});

it('delegates every read and handle to the real engine', function (): void {
    config()->set('laranail.settings.stores.settings.owners', ['user']);
    config()->set('laranail.settings.stores.other', ['layers' => ['runtime']]);
    $fake = Settings::fake();

    $fake->set('n.int', 3);
    $fake->set('n.float', 1.5);
    $fake->set('n.bool', true);
    $fake->set('n.string', 'x');
    $fake->set('n.array', [1]);
    $fake->store('other')->set('k', 'v');
    $fake->lock('n.int');
    $fake->unlock('n.int');

    expect($fake->integer('n.int'))->toBe(3)
        ->and($fake->float('n.float'))->toBe(1.5)
        ->and($fake->boolean('n.bool'))->toBeTrue()
        ->and($fake->string('n.string'))->toBe('x')
        ->and($fake->array('n.array'))->toBe([1])
        ->and($fake->has('n.int'))->toBeTrue()
        ->and($fake->lookup('n.int')->value())->toBe(3)
        ->and($fake->explain('n.int')->lookup->layer())->toBe('runtime')
        ->and($fake->store('other')->get('k'))->toBe('v')
        ->and($fake->locale('fr')->get('n.int'))->toBe(3)
        ->and($fake->for(new OwnerRef('user', 1))->get('n.int'))->toBeNull();
});
