<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;

beforeEach(function (): void {
    config()->set('laranail.settings.stores', [
        'settings'      => ['layers' => ['runtime'], 'owners' => ['tenant']],
        'team_settings' => ['layers' => ['runtime'], 'owners' => ['team']],
        'user_settings' => ['layers' => ['runtime'], 'owners' => ['user']],
    ]);
});

final readonly class FakeUser implements Settable
{
    public function __construct(private int $id, private ?int $teamId, private ?int $tenantId) {}

    public function settingsOwner(): OwnerRef
    {
        return new OwnerRef('user', $this->id);
    }

    public function settingsParents(): array
    {
        return OwnerRef::list(['team' => $this->teamId, 'tenant' => $this->tenantId]);
    }
}

it('routes an owner to the store that accepts its alias and keeps owners apart', function (): void {
    settings()->for(new OwnerRef('user', 1))->set('ui.theme', 'dark');
    settings()->for(new OwnerRef('user', 2))->set('ui.theme', 'light');

    expect(settings()->for(new OwnerRef('user', 1))->get('ui.theme'))->toBe('dark')
        ->and(settings()->for(new OwnerRef('user', 2))->get('ui.theme'))->toBe('light')
        ->and(settings()->store('user_settings')->for(new OwnerRef('user', 1))->get('ui.theme'))->toBe('dark')
        ->and(settings()->get('ui.theme'))->toBeNull();
});

it('refuses an owner alias no store accepts, or one the named store does not accept', function (): void {
    expect(fn (): mixed => settings()->for(new OwnerRef('product', 1))->get('ui.theme'))->toThrow(OwnerNotAllowed::class)
        ->and(fn (): mixed => settings()->store('settings')->for(new OwnerRef('user', 1))->get('ui.theme'))->toThrow(OwnerNotAllowed::class);
});

it('never falls back to the global value when inheritance is off', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->inStore('user_settings')->withDefault('none')->register();
    settings()->store('user_settings')->set('mail.password', TestCredentials::secret());

    expect(settings()->for(new OwnerRef('user', 1))->get('mail.password'))->toBe('none');
});

it('inherits from parents in their own stores, then from global, when the definition allows it', function (): void {
    app(DefinitionRegistry::class)->define('ui.theme')->inStore('user_settings')->inherits()->register();
    $user = new FakeUser(1, teamId: 7, tenantId: 3);

    settings()->store('user_settings')->set('ui.theme', 'global-theme');
    expect(settings()->for($user)->get('ui.theme'))->toBe('global-theme');

    settings()->for(new OwnerRef('tenant', 3))->set('ui.theme', 'tenant-theme');
    expect(settings()->for($user)->get('ui.theme'))->toBe('tenant-theme');

    settings()->for(new OwnerRef('team', 7))->set('ui.theme', 'team-theme');
    expect(settings()->for($user)->get('ui.theme'))->toBe('team-theme')
        ->and(settings()->for($user)->lookup('ui.theme')->scope()?->value)->toBe('team:7');

    settings()->for($user)->set('ui.theme', 'own-theme');
    expect(settings()->for($user)->get('ui.theme'))->toBe('own-theme');
});

it('lets a store default enable inheritance for keys without a definition', function (): void {
    config()->set('laranail.settings.stores.user_settings.inherits', true);

    settings()->for(new OwnerRef('team', 7))->set('ui.density', 'compact');

    expect(settings()->for(new FakeUser(1, teamId: 7, tenantId: null))->get('ui.density'))->toBe('compact');
});

it('refuses a write by an owner the definition does not let override it', function (): void {
    app(DefinitionRegistry::class)->define('ui.theme')->inStore('user_settings')->overridableBy('admin')->register();

    expect(fn () => settings()->for(new OwnerRef('user', 1))->set('ui.theme', 'dark'))
        ->toThrow(OwnerNotAllowed::class, 'cannot be overridden by [user]');
});
