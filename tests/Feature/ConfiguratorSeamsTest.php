<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Simtabi\Laranail\Package\Tools\Exceptions\PackageBootException;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Configurator\LayerSetup;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Configurator\StoreSetup;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Drivers\ArrayDriver;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Importers\ToolkitJsonImporter;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Services\Configurator\ConfiguratorRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Services\Transfer\Importers;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ContributorsProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\NobodyResolver;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedPackageContributor;
use Simtabi\Laranail\Settings\Verification\ClosureVerifier;
use Simtabi\Laranail\Settings\Verification\NullVerifier;

/** Boots with the application configurator, and the package contributor first when given. */
function bootSeams(Closure $app, ?Closure $package = null): void
{
    ScriptedConfigurator::$configure = $app;
    ScriptedPackageContributor::$configure = $package;
    ContributorsProvider::$classes = $package instanceof Closure ? [ScriptedPackageContributor::class, ScriptedConfigurator::class] : [ScriptedConfigurator::class];
    test()->bootWith([ContributorsProvider::class]);
}

/** The InvalidConfiguration behind a boot that stopped. */
function seamFailure(Closure $app, ?Closure $package = null): string
{
    try {
        bootSeams($app, $package);
    } catch (PackageBootException $exception) {
        return (string) $exception->getPrevious()?->getMessage();
    }

    throw new RuntimeException('The boot went on.');
}

function seamLogger(): AbstractLogger
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

function upperCaster(): Caster
{
    return new class implements Caster
    {
        public function toStorage(Key $key, mixed $value): mixed
        {
            return is_string($value) ? strtoupper($value) : $value;
        }

        public function fromStorage(mixed $stored): mixed
        {
            return $stored;
        }
    };
}

afterEach(function (): void {
    ContributorsProvider::$classes = [];
    ScriptedConfigurator::$configure = ScriptedPackageContributor::$configure = null;
});

it('stores through a driver the configurator registers, for every request', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->driver('memory', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver)
        ->layer('scratch', static fn (LayerSetup $layer): LayerSetup => $layer->driver('memory'))
        ->store('flags', static fn (StoreSetup $store): StoreSetup => $store->layers('scratch')));

    settings()->store('flags')->set('beta', true);

    expect(settings()->store('flags')->get('beta'))->toBeTrue();

    // A request's own driver still goes on top, validated against the same factory.
    settings()->configuration()->extendDriver('other', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver)->addLayer('other', ['driver' => 'other']);

    expect(settings()->configuration()->layers())->toHaveKey('other');
});

it('registers casters and definitions in code, casters first', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->caster('upper', upperCaster())
        ->define('site.code', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition->ofType('upper')->withDefault('none')));

    settings()->set('site.code', 'acme');

    expect(app(CasterRegistry::class)->has('upper'))->toBeTrue()
        ->and(app(DefinitionRegistry::class)->find(new StoreName('settings'), new Key('site.code'))?->type)->toBe('upper')
        ->and(settings()->get('site.code'))->toBe('ACME');
});

it('adds an importer to the importers config', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings->importer('legacy', ToolkitJsonImporter::class));

    expect(config('laranail.settings.importers.legacy'))->toBe(ToolkitJsonImporter::class)
        ->and(app(Importers::class)->names())->toContain('legacy')
        ->and(app(Importers::class)->get('legacy'))->toBeInstanceOf(ToolkitJsonImporter::class);
});

it('authorizes with the configurator over config, and a request over the configurator', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->authorizeUsing(static fn (Actor $actor, Ability $ability, Subject $subject): bool => $ability === Ability::View));

    expect(settings()->as(user(1))->get('site.name'))->toBeNull()
        ->and(fn (): mixed => settings()->as(user(1))->set('site.name', 'x'))->toThrow(AccessDenied::class, 'the authorization callback refused');

    settings()->configuration()->authorizeUsing(static fn (): bool => true)->verifyUsing(new NullVerifier);
    settings()->as(user(1))->set('site.name', 'Acme');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('verifies with a package contributor over config, and a request over the package', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings, static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->verifyUsing(static fn (Actor $actor, Subject $subject): bool => false));

    expect(app(Verifier::class))->toBeInstanceOf(ClosureVerifier::class)
        ->and(ConfiguratorRegistry::of(app())->applied()?->owner('verification.driver'))->toBe('laranail/settings');

    settings()->configuration()->verifyUsing($request = new NullVerifier);

    expect(app(RuntimeConfiguration::class)->verifier())->toBe($request);
});

it('resolves actors with a class the container builds, and requires an actor', function (): void {
    bootSeams(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->resolveActorsUsing(NobodyResolver::class)
        ->requireActor());

    expect(config('laranail.settings.authorization.require_actor'))->toBeTrue()
        ->and(app(ActorResolver::class))->toBeInstanceOf(NobodyResolver::class);
});

it('adds audit sinks from every owner, in order, and sends the log where it is told', function (): void {
    $package = new InMemoryAuditSink;
    $application = new InMemoryAuditSink;
    $logger = seamLogger();

    bootSeams(
        static fn (SettingsSetup $settings): SettingsSetup => $settings->auditTo($application)->logTo($logger),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->auditTo($package),
    );

    settings()->set('site.name', 'Acme');

    expect($package->entries())->not->toBeEmpty()
        ->and($application->entries())->toHaveCount(count($package->entries()))
        ->and(ConfiguratorRegistry::of(app())->behaviour()->auditSinks)->toBe([$package, $application])
        ->and($logger->messages)->toContain('laranail/settings audit: update allowed');
});

it('refuses a behaviour name two owners declare, and an application decision from a package', function (Closure $app, Closure $package, string $message): void {
    expect(seamFailure($app, $package))->toContain($message);
})->with([
    'one driver name' => [
        static fn (SettingsSetup $settings): SettingsSetup => $settings->driver('memory', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->driver('memory', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver),
        'laranail.settings.drivers.memory is owned by the package [laranail/settings]',
    ],
    'one definition' => [
        static fn (SettingsSetup $settings): SettingsSetup => $settings->define('site.name', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->define('site.name', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition),
        'laranail.settings.definitions.site.name is owned by the package',
    ],
    'the verifier' => [
        static fn (SettingsSetup $settings): SettingsSetup => $settings->verifyUsing(new NullVerifier),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->verifyUsing(new NullVerifier),
        'laranail.settings.verification.driver is owned by the package',
    ],
    'the authorizer, from a package' => [
        static fn (SettingsSetup $settings): SettingsSetup => $settings,
        static fn (SettingsSetup $settings): SettingsSetup => $settings->authorizeUsing(static fn (): bool => true),
        'authorizeUsing() is the application\'s decision',
    ],
    'the actor resolver, from a package' => [
        static fn (SettingsSetup $settings): SettingsSetup => $settings,
        static fn (SettingsSetup $settings): SettingsSetup => $settings->resolveActorsUsing(static fn (): null => null),
        'resolveActorsUsing() is the application\'s decision',
    ],
]);

it('refuses a behaviour value it cannot use, naming the method', function (Closure $app, string $message): void {
    expect(seamFailure($app))->toContain($message);
})->with([
    'a built-in driver name' => [static fn (SettingsSetup $settings): SettingsSetup => $settings->driver('json', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver), 'driver() (laranail.settings.drivers.json): expected a name that is not a built-in driver'],
    'a class that is no importer' => [static fn (SettingsSetup $settings): SettingsSetup => $settings->importer('legacy', stdClass::class), 'importer() (laranail.settings.importers.legacy)'],
    'a class that is no verifier' => [static fn (SettingsSetup $settings): SettingsSetup => $settings->verifyUsing(stdClass::class), 'verifyUsing() (laranail.settings.verification.driver)'],
    'a bad caster alias' => [static fn (SettingsSetup $settings): SettingsSetup => $settings->caster('Upper', upperCaster()), 'caster() (laranail.settings.casters.Upper)'],
    'a bad key' => [static fn (SettingsSetup $settings): SettingsSetup => $settings->define('', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition), 'define() (laranail.settings.definitions)'],
]);

it('keeps behaviour when config is cached, since config cannot hold it', function (): void {
    $registry = new ConfiguratorRegistry;
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->driver('memory', static fn (LayerConfig $layer): ArrayDriver => new ArrayDriver);
    $registry->register(ScriptedConfigurator::class, __FILE__, __LINE__, static function (): void {});
    $written = [];

    $registry->apply(app(), true, static function (string $path, mixed $value) use (&$written): void {
        $written[$path] = $value;
    }, static function (): void {});

    expect($written)->toBe([])
        ->and($registry->behaviour()->drivers)->toHaveKey('memory');
});
