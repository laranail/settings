<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

it('lets trusted code without an actor read and write without consulting any gate', function (): void {
    Gate::before(static fn (): never => throw new LogicException('the gate must not be asked'));

    settings()->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('allows an actor whose gates allow the ability', function (): void {
    allowGates(Ability::View, Ability::Update);

    settings()->as(user())->set('site.name', 'Acme');

    expect(settings()->as(user())->get('site.name'))->toBe('Acme');
});

it('denies an actor whose gate refuses, naming the ability, store and key but no value', function (): void {
    allowGates(Ability::View);
    Gate::define('laranail-settings.update', static fn (TestUser $user): bool => false);

    try {
        settings()->as(user())->set('site.name', 'a-value-that-must-not-leak');
        $this->fail('Expected AccessDenied.');
    } catch (AccessDenied $denied) {
        expect($denied->ability)->toBe(Ability::Update)
            ->and($denied->store)->toBe('settings')
            ->and($denied->key)->toBe('site.name')
            ->and($denied->getMessage())->toContain('[update] on [settings] key [site.name]')
            ->and($denied->getMessage())->not->toContain('a-value-that-must-not-leak');
    }

    expect(settings()->get('site.name'))->toBeNull();
});

it('passes the subject to the gate', function (): void {
    Gate::define('laranail-settings.update', static fn (TestUser $user, Subject $subject): bool => $subject->key?->value === 'site.name' && $subject->store->value === 'settings');

    settings()->as(user())->set('site.name', 'Acme');

    expect(fn () => settings()->as(user())->set('site.other', 'x'))->toThrow(AccessDenied::class);
});

it('denies an ability whose gate is not defined, by default', function (): void {
    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(AccessDenied::class, 'gate [laranail-settings.view] is not defined');
});

it('allows undefined abilities when authorization.undefined is allow', function (): void {
    config()->set('laranail.settings.authorization.undefined', 'allow');

    settings()->as(user())->set('site.name', 'Acme');

    expect(settings()->as(user())->get('site.name'))->toBe('Acme');
});

it('lets a Gate::before callback grant an undefined ability', function (): void {
    Gate::before(static fn (TestUser $user): ?bool => $user->getKey() === 7 ? true : null);

    settings()->as(user(7))->set('site.name', 'Acme');

    expect(fn () => settings()->as(user(8))->set('site.name', 'x'))->toThrow(AccessDenied::class);
});

it('rejects an authorization.undefined value other than allow or deny', function (): void {
    config()->set('laranail.settings.authorization.undefined', 'maybe');

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(InvalidConfiguration::class, 'authorization.undefined');
});

it('checks permissions through the user\'s own can() when the permissions driver is chosen', function (): void {
    config()->set('laranail.settings.authorization.driver', 'permissions');
    config()->set('laranail.settings.authorization.permissions', ['view' => 'settings.view', 'update' => 'settings.update.{store}']);
    Gate::define('settings.view', static fn (TestUser $user): bool => true);
    Gate::define('settings.update.settings', static fn (TestUser $user): bool => $user->getKey() === 1);

    settings()->as(user(1))->set('site.name', 'Acme');

    expect(settings()->as(user(2))->get('site.name'))->toBe('Acme')
        ->and(fn () => settings()->as(user(2))->set('site.name', 'x'))->toThrow(AccessDenied::class, 'permission [settings.update.settings] is missing')
        ->and(fn () => settings()->as(user(1))->forget('site.name'))->toThrow(AccessDenied::class, 'no permission is mapped to [forget]');
});

it('rejects a permissions map naming an unknown ability', function (): void {
    config()->set('laranail.settings.authorization.driver', 'permissions');
    config()->set('laranail.settings.authorization.permissions', ['fly' => 'settings.fly']);

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(InvalidConfiguration::class, 'authorization.permissions.fly');
});

it('denies a permissions check for an actor that cannot hold permissions', function (): void {
    config()->set('laranail.settings.authorization.driver', 'permissions');

    $actor = new Actor(ActorType::Service, id: 'billing');

    expect(fn (): mixed => settings()->as($actor)->get('site.name'))->toThrow(AccessDenied::class, 'cannot hold permissions');
});

it('chains authorizers: the first that does not abstain decides', function (): void {
    config()->set('laranail.settings.authorization.driver', ['gate', 'permissions']);
    config()->set('laranail.settings.authorization.permissions', ['view' => 'settings.view']);
    Gate::define('settings.view', static fn (TestUser $user): bool => true);
    Gate::define('laranail-settings.update', static fn (TestUser $user): bool => false);

    // view: gate undefined (abstains) → permissions allow
    expect(settings()->as(user())->get('site.name'))->toBeNull()
        // update: gate denies; the chain stops there
        ->and(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(AccessDenied::class, 'gate [laranail-settings.update] denied')
        // forget: everyone abstains → undefined → deny
        ->and(fn () => settings()->as(user())->forget('site.name'))->toThrow(AccessDenied::class, 'no authorizer decided');
});

it('accepts a custom authorizer class by name', function (): void {
    config()->set('laranail.settings.authorization.driver', AllowViewOnly::class);

    expect(settings()->as(user())->get('site.name'))->toBeNull()
        ->and(fn () => settings()->as(user())->set('site.name', 'x'))->toThrow(AccessDenied::class, 'view only');
});

it('rejects an authorizer that is neither built in nor an Authorizer class', function (string|array $driver): void {
    config()->set('laranail.settings.authorization.driver', $driver);

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(InvalidConfiguration::class, 'authorization.driver');
})->with([
    'unknown name'      => ['ldap'],
    'not an authorizer' => [stdClass::class],
    'bad chain entry'   => [['gate', 'nope']],
]);

it('treats a token as an extra ceiling: the token must hold the ability as well as the gate', function (): void {
    allowGates(Ability::View, Ability::Update);

    $limited = Actor::user(user(), abilities: ['laranail-settings:view']);
    $wildcard = Actor::user(user(), abilities: ['laranail-settings:*']);
    $everything = Actor::user(user(), abilities: ['*']);

    expect(settings()->as($limited)->get('site.name'))->toBeNull()
        ->and(fn () => settings()->as($limited)->set('site.name', 'x'))->toThrow(AccessDenied::class, 'the token lacks [laranail-settings:update]');

    settings()->as($wildcard)->set('site.name', 'Acme');
    settings()->as($everything)->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('never lets a token grant what the gate refuses', function (): void {
    allowGates(Ability::View);
    Gate::define('laranail-settings.update', static fn (TestUser $user): bool => false);

    expect(fn () => settings()->as(Actor::user(user(), abilities: ['laranail-settings:*']))->set('site.name', 'x'))->toThrow(AccessDenied::class);
});

it('runs an explicit system actor as trusted code', function (): void {
    settings()->asSystem('nightly sync')->set('site.name', 'Acme');

    expect(settings()->asSystem()->get('site.name'))->toBe('Acme');
});

it('refuses calls without an actor when an actor is required, but allows asSystem()', function (): void {
    config()->set('laranail.settings.authorization.require_actor', true);
    allowGates(Ability::View, Ability::Update);

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(ActorRequired::class)
        ->and(fn () => settings()->set('site.name', 'x'))->toThrow(ActorRequired::class, 'call as($user) or asSystem()');

    settings()->asSystem('migration')->set('site.name', 'Acme');
    settings()->as(user())->set('site.tagline', 'Hi');

    expect(settings()->as(user())->get('site.name'))->toBe('Acme');
});

it('audits a refusal for a missing actor', function (): void {
    config()->set('laranail.settings.authorization.require_actor', true);
    $sink = auditInMemory();

    expect(fn () => settings()->set('site.name', 'x'))->toThrow(ActorRequired::class);

    $denied = array_values(array_filter($sink->entries(), static fn (AuditEntry $entry): bool => $entry->action === AuditAction::Update));

    expect($denied)->toHaveCount(1)
        ->and($denied[0]->meta['refusal'])->toBe('actor_required')
        ->and($denied[0]->actorType->value)->toBe('system');
});

it('lets a custom authorizer return requiresApproval, which this release refuses', function (): void {
    settings()->configuration()->authorizeUsing(static fn (): Decision => Decision::requiresApproval('four eyes'));

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(ApprovalUnavailable::class);
});

final class AllowViewOnly implements Authorizer
{
    public function authorize(Actor $actor, Ability $ability, Subject $subject): Decision
    {
        return $ability === Ability::View ? Decision::allow() : Decision::deny('view only');
    }
}

it('closes a guard that denies every actor and lets only trusted code through', function (): void {
    $guard = AccessGuard::closed();
    $subject = new Subject(new StoreName('settings'));

    expect(fn () => $guard->authorize(Actor::user(user()), Ability::View, $subject))->toThrow(AccessDenied::class);

    $guard->authorize(null, Ability::Update, $subject);
    $guard->authorize(Actor::system('test'), Ability::Update, $subject);
});
