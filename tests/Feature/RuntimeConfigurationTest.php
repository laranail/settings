<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Data\Decision;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Verification\NullVerifier;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Authorization\ClosureAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Authentication\ClosureActorResolver;

/** A logger that keeps messages, for runtime logTo() tests. */
function memoryLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

it('adds a store and a layer for this request and reads through them', function (): void {
    settings()->configuration()
        ->addLayer('memory', ['driver' => 'array'])
        ->addStore('flags', ['layers' => ['memory'], 'owners' => ['team']]);

    settings()->store('flags')->set('beta', true);

    expect(settings()->store('flags')->get('beta'))->toBeTrue()
        ->and(settings()->configuration()->stores())->toHaveKey('flags')
        ->and(settings()->configuration()->layers())->toHaveKey('memory');
});

it('validates a store or layer exactly like config, and keeps nothing it rejected', function (): void {
    $configuration = settings()->configuration();

    expect(fn (): RuntimeConfiguration => $configuration->addStore('flags', ['layers' => ['nowhere']]))->toThrow(InvalidConfiguration::class, 'layer [nowhere] is not defined')
        ->and(fn (): RuntimeConfiguration => $configuration->addStore('Bad-Name', ['layers' => ['runtime']]))->toThrow(InvalidConfiguration::class, 'Invalid store name')
        ->and(fn (): RuntimeConfiguration => $configuration->addLayer('memory', ['driver' => 'redis']))->toThrow(InvalidConfiguration::class, 'unknown driver [redis]')
        ->and(fn (): RuntimeConfiguration => $configuration->addLayer('memory', []))->toThrow(InvalidConfiguration::class, 'a driver name is required')
        ->and($configuration->stores())->toBe([])
        ->and($configuration->layers())->toBe([])
        ->and(fn (): mixed => settings()->store('flags')->get('beta'))->toThrow(UnknownStore::class);
});

it('registers a custom driver for this request only', function (): void {
    $driver = new ArrayDriver;

    settings()->configuration()
        ->extendDriver('custom', static fn (LayerConfig $layer): ArrayDriver => $driver)
        ->addLayer('custom_layer', ['driver' => 'custom'])
        ->addStore('custom_store', ['layers' => ['custom_layer']]);

    settings()->store('custom_store')->set('a', 1);

    expect(settings()->store('custom_store')->get('a'))->toBe(1)
        ->and(fn (): RuntimeConfiguration => settings()->configuration()->extendDriver('json', static fn (LayerConfig $layer): ArrayDriver => $driver))->toThrow(InvalidConfiguration::class, 'is built in');
});

it('defines a setting for this request that wins over the shared registry', function (): void {
    settings()->configuration()->define('site.seats')->ofType('int')->withDefault(5)->register();

    expect(settings()->get('site.seats'))->toBe(5)
        ->and(settings()->configuration()->findDefinition(new StoreName('settings'), new Key('site.seats'))?->default)->toBe(5);
});

it('replaces the authorizer, verifier and actor resolver for this request', function (): void {
    config()->set('laranail.settings.verification.abilities', ['update']);

    settings()->configuration()
        ->authorizeUsing(static fn (Actor $actor, Ability $ability, Subject $subject): bool => $ability !== Ability::Forget)
        ->verifyUsing(static fn (Actor $actor, Subject $subject): bool => $actor->id === '1')
        ->resolveActorsUsing(static fn (?Request $request): Actor => Actor::user(user(1)));

    settings()->asCurrentActor()->set('site.name', 'Acme');

    expect(settings()->as(user(1))->get('site.name'))->toBe('Acme')
        ->and(fn () => settings()->as(user(2))->set('site.name', 'x'))->toThrow(VerificationRequired::class)
        ->and(fn () => settings()->as(user(1))->forget('site.name'))->toThrow(AccessDenied::class, 'the authorization callback refused');
});

it('accepts contract instances as well as closures', function (): void {
    $configuration = settings()->configuration()
        ->authorizeUsing(new ClosureAuthorizer(static fn (): Decision => Decision::allow()))
        ->verifyUsing(new NullVerifier)
        ->resolveActorsUsing(new ClosureActorResolver(static fn (): null => null));

    settings()->as(user())->set('site.name', 'Acme');

    expect($configuration->authorizer())->not->toBeNull()
        ->and($configuration->verifier())->not->toBeNull()
        ->and($configuration->actorResolver())->not->toBeNull();
});

it('requires an actor for this request, and can allow actorless calls against config', function (): void {
    settings()->configuration()->requireActor();

    expect(fn (): mixed => settings()->get('site.name'))->toThrow(ActorRequired::class)
        ->and(settings()->configuration()->actorRequirement())->toBeTrue();

    settings()->configuration()->allowActorless();
    expect(settings()->get('site.name'))->toBeNull();

    config()->set('laranail.settings.authorization.require_actor', true);
    app()->forgetScopedInstances();
    settings()->configuration()->allowActorless();

    expect(settings()->get('site.name'))->toBeNull();
});

it('sends audit entries and the log to runtime destinations, or switches the log off', function (): void {
    $logger = memoryLogger();
    $sink = new InMemoryAuditSink;

    settings()->configuration()->auditTo($sink)->logTo($logger);
    settings()->set('site.name', 'Acme');

    expect($sink->entries())->not->toBeEmpty()
        ->and($logger->messages)->toContain('laranail/settings audit: update allowed');

    settings()->configuration()->logTo(false);
    $before = count($logger->messages);
    settings()->set('site.name', 'Other');

    expect($logger->messages)->toHaveCount($before);
});

it('applies changes for one callback and restores them afterwards, even when it throws', function (): void {
    $result = settings()->withConfiguration(
        static fn (RuntimeConfiguration $configuration): RuntimeConfiguration => $configuration
            ->addLayer('memory', ['driver' => 'array'])
            ->addStore('flags', ['layers' => ['memory']])
            ->requireActor(),
        static fn (): string => settings()->store('flags')->asSystem()->get('beta', 'inside'),
    );

    expect($result)->toBe('inside')
        ->and(settings()->configuration()->stores())->toBe([])
        ->and(settings()->configuration()->actorRequirement())->toBeNull()
        ->and(fn (): mixed => settings()->store('flags')->get('beta'))->toThrow(UnknownStore::class);

    expect(fn (): mixed => settings()->withConfiguration(
        static fn (RuntimeConfiguration $configuration): RuntimeConfiguration => $configuration->requireActor(),
        static fn (): never => throw new RuntimeException('boom'),
    ))->toThrow(RuntimeException::class, 'boom');

    expect(settings()->configuration()->actorRequirement())->toBeNull()
        ->and(settings()->get('site.name'))->toBeNull();
});

it('restores a definition registered inside withConfiguration', function (): void {
    settings()->withConfiguration(
        static fn (RuntimeConfiguration $configuration): mixed => $configuration->define('site.seats')->withDefault(5)->register(),
        static fn (): mixed => expect(settings()->get('site.seats'))->toBe(5),
    );

    expect(settings()->get('site.seats'))->toBeNull();
});

it('is scoped: a new request starts with none of the previous request\'s changes', function (): void {
    settings()->configuration()->addLayer('memory', ['driver' => 'array'])->addStore('flags', ['layers' => ['memory']])->requireActor();

    app()->forgetScopedInstances();

    expect(settings()->configuration()->stores())->toBe([])
        ->and(settings()->get('site.name'))->toBeNull();
});

it('bumps its generation on every change so built stores are rebuilt', function (): void {
    $configuration = settings()->configuration();
    $start = $configuration->generation();

    $configuration->requireActor()->allowActorless();

    expect($configuration->generation())->toBe($start + 2);
});

it('refuses configuration() on a hand-built manager without runtime configuration', function (): void {
    $manager = new SettingsManager(
        app(StoreRegistry::class),
        app(DefinitionRegistry::class),
        app(Resolver::class),
        app(SettingWriter::class),
    );

    $manager->set('site.name', 'Acme');

    expect($manager->get('site.name'))->toBe('Acme')
        ->and(fn (): RuntimeConfiguration => $manager->configuration())->toThrow(LogicException::class, 'without runtime configuration')
        ->and(fn (): mixed => $manager->as(user())->get('site.name'))->toThrow(AccessDenied::class)
        ->and(fn (): SettingsManager => $manager->asCurrentActor())->toThrow(ActorRequired::class);
});
