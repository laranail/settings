<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\Channel;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Data\ActorContext;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Authentication\GuardActorResolver;
use Simtabi\Laranail\Settings\Authentication\SanctumActorResolver;

it('builds a user actor from an authenticatable, keyed by morph alias and id', function (): void {
    $actor = Actor::user(user(42));

    expect($actor->type)->toBe(ActorType::User)
        ->and($actor->id)->toBe('42')
        ->and($actor->alias)->toBe(TestUser::class)
        ->and($actor->user)->toBeInstanceOf(TestUser::class)
        ->and($actor->channel)->toBe(Channel::Php)
        ->and($actor->abilities)->toBeNull()
        ->and($actor->isSystem())->toBeFalse();
});

it('makes an actor with token abilities a token actor', function (): void {
    expect(Actor::user(user(), abilities: ['settings:view'])->type)->toBe(ActorType::Token);
});

it('builds an explicit system actor carrying its reason', function (): void {
    $actor = Actor::system('nightly sync', Channel::Queue);

    expect($actor->isSystem())->toBeTrue()
        ->and($actor->user)->toBeNull()
        ->and($actor->context->reason)->toBe('nightly sync')
        ->and($actor->channel)->toBe(Channel::Queue);
});

it('never serialises the authenticatable, keeping identifiers and context', function (): void {
    $actor = Actor::user(
        user(7),
        Channel::Rest,
        ['settings:view'],
        new ActorContext('10.0.0.1', 'agent', 'req-1', 'INC-1'),
        now()->toImmutable()->startOfSecond(),
    );

    $payload = serialize($actor);
    $copy = unserialize($payload);

    expect($payload)->not->toContain('attributes')->not->toContain('O:' . strlen(TestUser::class))
        ->and($copy)->toBeInstanceOf(Actor::class)
        ->and($copy->user)->toBeNull()
        ->and($copy->id)->toBe('7')
        ->and($copy->type)->toBe(ActorType::Token)
        ->and($copy->channel)->toBe(Channel::Rest)
        ->and($copy->abilities)->toBe(['settings:view'])
        ->and($copy->verifiedAt?->equalTo($actor->verifiedAt))->toBeTrue()
        ->and($copy->context)->toEqual($actor->context);
});

it('truncates the user agent to 255 characters', function (): void {
    expect(mb_strlen((string) (new ActorContext(userAgent: str_repeat('a', 300)))->userAgent))->toBe(255);
});

it('reads ip, user agent and request id from a request', function (): void {
    $request = Request::create('/', server: ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'curl', 'HTTP_X_REQUEST_ID' => 'abc']);

    $context = ActorContext::fromRequest($request);

    expect($context->ip)->toBe('10.1.2.3')
        ->and($context->userAgent)->toBe('curl')
        ->and($context->requestId)->toBe('abc');
});

it('derives immutable handles for as(), asSystem() and because()', function (): void {
    $base = settings();
    $acting = $base->as(user());
    $because = $acting->because('INC-123');

    expect($base->actor())->toBeNull()
        ->and($acting->actor()?->id)->toBe('1')
        ->and($acting->actor()?->context->reason)->toBeNull()
        ->and($because->actor()?->context->reason)->toBe('INC-123')
        ->and($base->asSystem('cron')->actor()?->isSystem())->toBeTrue()
        ->and($base->because('INC-9')->asSystem()->actor()?->context->reason)->toBe('INC-9')
        ->and($base->because('INC-9')->as(user())->actor()?->context->reason)->toBe('INC-9');
});

it('resolves the current actor from the configured guards in order', function (): void {
    config()->set('auth.guards.second', ['driver' => 'session', 'provider' => 'users']);
    config()->set('laranail.settings.authentication.guards', ['web', 'second']);
    Auth::guard('second')->setUser(user(5));
    Gate::define('laranail-settings.view', static fn (TestUser $user): bool => $user->getKey() === 5);

    expect(settings()->asCurrentActor()->actor()?->id)->toBe('5')
        ->and(settings()->asCurrentActor()->get('site.name'))->toBeNull();
});

it('uses the default guard when no guards are configured', function (): void {
    Auth::guard()->setUser(user(3));

    expect(settings()->asCurrentActor()->actor()?->id)->toBe('3');
});

it('refuses asCurrentActor() when nobody is authenticated', function (): void {
    expect(fn (): Settings => settings()->asCurrentActor())->toThrow(ActorRequired::class, 'No authenticated actor');
});

it('captures request context when resolving from a request', function (): void {
    Auth::guard()->setUser(user());
    $request = Request::create('/', server: ['REMOTE_ADDR' => '10.9.9.9']);

    expect(settings()->asCurrentActor($request)->actor()?->context->ip)->toBe('10.9.9.9');
});

it('copies Sanctum token abilities into the actor, and leaves a token-less session unlimited', function (): void {
    $user = user();
    $user->tokenAbilities = ['settings:view', 42];
    Auth::guard()->setUser($user);
    $resolver = new SanctumActorResolver(app('auth'), ['web'], static fn (): null => null, stdClass::class);

    expect($resolver->resolve()?->abilities)->toBe(['settings:view'])
        ->and($resolver->resolve()?->type)->toBe(ActorType::Token);

    $user->tokenAbilities = null;

    expect($resolver->resolve()?->abilities)->toBeNull();
});

it('returns no actor from the Sanctum resolver when nobody is authenticated', function (): void {
    config()->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);

    expect(new SanctumActorResolver(app('auth'), [], static fn (): null => null, stdClass::class)->resolve())->toBeNull();
});

it('refuses the sanctum resolver when laravel/sanctum is not installed', function (): void {
    config()->set('laranail.settings.authentication.resolver', 'sanctum');

    expect(fn (): Settings => settings()->asCurrentActor())->toThrow(InvalidConfiguration::class, 'needs laravel/sanctum');
});

it('accepts a custom resolver class and rejects an unknown one', function (): void {
    config()->set('laranail.settings.authentication.resolver', FixedResolver::class);

    expect(settings()->asCurrentActor()->actor()?->id)->toBe('99');

    config()->set('laranail.settings.authentication.resolver', 'ldap');
    app()->forgetScopedInstances();

    expect(fn (): Settings => settings()->asCurrentActor())->toThrow(InvalidConfiguration::class, 'authentication.resolver');
});

it('resolves with the guard resolver directly, outside HTTP', function (): void {
    $resolver = new GuardActorResolver(app('auth'), [], static fn (): null => null);

    expect($resolver->resolve())->toBeNull();

    Auth::guard()->setUser(user(2));

    expect($resolver->resolve()?->context->ip)->toBeNull();
});

it('lets a token-bearing actor be checked against the token ceiling only for the asked ability', function (): void {
    expect(Actor::user(user(), abilities: ['settings:update'])->tokenAllows(Ability::Update))->toBeTrue()
        ->and(Actor::user(user(), abilities: ['settings:update'])->tokenAllows(Ability::View))->toBeFalse()
        ->and(Actor::user(user())->tokenAllows(Ability::View))->toBeTrue();
});

final class FixedResolver implements ActorResolver
{
    public function resolve(?Request $request = null): Actor
    {
        return Actor::user(TestUser::make(99));
    }
}
