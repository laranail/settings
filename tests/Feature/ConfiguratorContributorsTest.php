<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Exceptions\PackageBootException;
use Simtabi\Laranail\Settings\Configurator\AppliedSetup;
use Simtabi\Laranail\Settings\Configurator\CacheSetup;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;
use Simtabi\Laranail\Settings\Configurator\Contribution;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Configurator\StoreSetup;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Enums\ContributionStatus;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Services\Configurator\ConfiguratorRegistry;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ContributorsProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\OtherModuleContributor;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\OtherPackageContributor;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\PrefixConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedModuleContributor;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedPackageContributor;

/** The package fixtures live in this repository, so their Composer identity is its root package. */
const PACKAGE = 'laranail/settings';

/** Boots with these classes registered, in this order, each running its closure. */
function bootContributors(array $classes): void
{
    ContributorsProvider::$classes = $classes;
    test()->bootWith([ContributorsProvider::class]);
}

function store(string $name): Closure
{
    return static fn (SettingsSetup $settings): SettingsSetup => $settings->store($name, static fn (StoreSetup $store): StoreSetup => $store->layers('runtime'));
}

function appliedSetup(): AppliedSetup
{
    return app(ConfiguratorRegistry::class)->applied() ?? throw new RuntimeException('Nothing was applied.');
}

/** @return array<string, ContributionStatus> identity => status */
function statuses(): array
{
    return array_column(array_map(static fn (Contribution $contribution): array => [$contribution->registration->identity, $contribution->status], appliedSetup()->contributions()), 1, 0);
}

/** The InvalidConfiguration behind a boot that stopped. */
function bootFailure(array $classes): string
{
    try {
        bootContributors($classes);
    } catch (PackageBootException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(InvalidConfiguration::class);

        return (string) $exception->getPrevious()?->getMessage();
    }

    throw new RuntimeException('The boot went on.');
}

beforeEach(function (): void {
    ScriptedPackageContributor::$configure = store('billing');
    ScriptedModuleContributor::$configure = store('invoices');
    ScriptedConfigurator::$configure = store('teams');
});

afterEach(function (): void {
    ContributorsProvider::$classes = [];
    ScriptedConfigurator::$configure = ScriptedPackageContributor::$configure = ScriptedModuleContributor::$configure = null;
    OtherModuleContributor::$configure = OtherPackageContributor::$configure = null;
});

it('registers each scope and records its identity and call site', function (): void {
    bootContributors([ScriptedConfigurator::class, ScriptedPackageContributor::class, ScriptedModuleContributor::class]);

    $registrations = app(ConfiguratorRegistry::class)->registrations();

    expect(array_map(static fn (ConfiguratorRegistration $registration): array => [$registration->scope, $registration->identity], $registrations))->toBe([
        [ConfiguratorScope::Application, 'app'],
        [ConfiguratorScope::Package, PACKAGE],
        [ConfiguratorScope::Module, 'Billing'],
    ])
        ->and(array_filter($registrations, static fn (ConfiguratorRegistration $registration): bool => ! str_ends_with($registration->file, 'ContributorsProvider.php')))->toBe([])
        ->and(Settings::configuratorRegistration()?->class)->toBe(ScriptedConfigurator::class);
});

it('applies packages, then modules, then the application in loose mode, the default', function (): void {
    bootContributors([ScriptedConfigurator::class, ScriptedModuleContributor::class, ScriptedPackageContributor::class]);

    $applied = appliedSetup();

    expect($applied->mode)->toBe(ConfiguratorMode::Loose)
        ->and(array_map(static fn (Contribution $contribution): string => $contribution->registration->identity, $applied->contributions()))->toBe([PACKAGE, 'Billing', 'app'])
        ->and(statuses())->toBe([PACKAGE => ContributionStatus::Applied, 'Billing' => ContributionStatus::Applied, 'app' => ContributionStatus::Applied])
        ->and(config('laranail.settings.stores.billing.layers'))->toBe(['runtime'])
        ->and(config('laranail.settings.stores.invoices.layers'))->toBe(['runtime'])
        ->and(config('laranail.settings.stores.teams.layers'))->toBe(['runtime'])
        ->and($applied->owner('stores.billing.layers'))->toBe(PACKAGE)
        ->and($applied->owner('stores.invoices.layers'))->toBe('Billing')
        ->and($applied->owner('stores.teams.layers'))->toBe('app');
});

it('applies contributors with no application configurator at all', function (): void {
    bootContributors([ScriptedPackageContributor::class]);

    expect(config('laranail.settings.stores.billing.layers'))->toBe(['runtime'])
        ->and(appliedSetup()->registration)->toBeNull();
});

it('refuses every contributor in strict mode, keeping what each would have added', function (): void {
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => store('teams')($settings)->mode(ConfiguratorMode::Strict);

    bootContributors([ScriptedPackageContributor::class, ScriptedModuleContributor::class, ScriptedConfigurator::class]);

    $refused = appliedSetup()->contributions(ConfiguratorScope::Package)[0];

    expect(appliedSetup()->mode)->toBe(ConfiguratorMode::Strict)
        ->and(statuses())->toBe([PACKAGE => ContributionStatus::Refused, 'Billing' => ContributionStatus::Refused, 'app' => ContributionStatus::Applied])
        ->and(config('laranail.settings.stores.billing'))->toBeNull()
        ->and(config('laranail.settings.stores.invoices'))->toBeNull()
        ->and(config('laranail.settings.stores.teams.layers'))->toBe(['runtime'])
        ->and($refused->values)->toBe(['stores.billing.layers' => ['runtime']]);
});

it('refuses a name declared by two owners in any pairing, naming both', function (array $classes, Closure $setup, string $first, string $second): void {
    $setup();

    $message = bootFailure($classes);

    expect($message)->toContain('laranail.settings.stores.billing is owned by the '.$first)
        ->and($message)->toContain('the '.$second);
})->with([
    'package and module' => [
        [ScriptedPackageContributor::class, ScriptedModuleContributor::class],
        static function (): void {
            ScriptedModuleContributor::$configure = store('billing');
        },
        'package [laranail/settings]',
        'module [Billing]',
    ],
    'package and application' => [
        [ScriptedPackageContributor::class, ScriptedConfigurator::class],
        static function (): void {
            ScriptedConfigurator::$configure = store('billing');
        },
        'package [laranail/settings]',
        ScriptedConfigurator::class,
    ],
    'module and application' => [
        [ScriptedModuleContributor::class, ScriptedConfigurator::class],
        static function (): void {
            ScriptedModuleContributor::$configure = store('billing');
            ScriptedConfigurator::$configure = store('billing');
        },
        'module [Billing]',
        ScriptedConfigurator::class,
    ],
    'two modules' => [
        [ScriptedModuleContributor::class, OtherModuleContributor::class],
        static function (): void {
            ScriptedModuleContributor::$configure = store('billing');
            OtherModuleContributor::$configure = store('billing');
        },
        'module [Billing]',
        'module [Reports]',
    ],
]);

it('refuses one setting path set by two owners, outside named entries too', function (): void {
    ScriptedPackageContributor::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->cache(static fn (CacheSetup $cache): CacheSetup => $cache->ttl(60));
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->cache(static fn (CacheSetup $cache): CacheSetup => $cache->ttl(600));

    expect(bootFailure([ScriptedPackageContributor::class, ScriptedConfigurator::class]))->toContain('laranail.settings.cache.ttl is owned by the package [laranail/settings]');
});

it('lets one owner change its own entry as often as it likes', function (): void {
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => store('teams')($settings)
        ->store('teams', static fn (StoreSetup $store): StoreSetup => $store->inherits());

    bootContributors([ScriptedConfigurator::class]);

    expect(config('laranail.settings.stores.teams'))->toBe(['layers' => ['runtime'], 'inherits' => true]);
});

it('refuses an application-wide decision from a package or a module, naming it', function (string $class, Closure $decision, string $method): void {
    ScriptedPackageContributor::$configure = ScriptedModuleContributor::$configure = $decision;

    expect(bootFailure([$class]))->toContain($method.' is the application\'s decision');
})->with([
    'package tables' => [ScriptedPackageContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->tables(prefix: 'pkg_'), 'tables()'],
    'module store' => [ScriptedModuleContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->defaultStore('invoices'), 'defaultStore()'],
    'package tenancy' => [ScriptedPackageContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->tenancy(static fn ($tenancy) => $tenancy->column()), 'tenancy()'],
    'module http' => [ScriptedModuleContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->http(static fn ($http) => $http->enabled()), 'http()'],
    'package mode' => [ScriptedPackageContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->mode(ConfiguratorMode::Strict), 'mode()'],
    'module exclusion' => [ScriptedModuleContributor::class, static fn (SettingsSetup $settings): SettingsSetup => $settings->exclude(package: PACKAGE), 'exclude()'],
]);

it('lets the application exclude a package or a module on the record, and take its names', function (): void {
    ScriptedModuleContributor::$configure = store('billing');
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => store('billing')($settings)
        ->exclude(package: PACKAGE)
        ->exclude(module: 'Billing');

    bootContributors([ScriptedPackageContributor::class, ScriptedModuleContributor::class, ScriptedConfigurator::class]);

    $excluded = appliedSetup()->contributions(ConfiguratorScope::Package)[0];

    expect(statuses())->toBe([PACKAGE => ContributionStatus::Excluded, 'Billing' => ContributionStatus::Excluded, 'app' => ContributionStatus::Applied])
        ->and(appliedSetup()->owner('stores.billing.layers'))->toBe('app')
        ->and($excluded->excludedAt)->toContain('ConfiguratorContributorsTest.php:');
});

it('refuses a second contributor with the same identity, or the same class twice', function (array $classes, string $message): void {
    expect(fn () => bootContributors($classes))->toThrow(SettingsMisuse::class, $message);
})->with([
    'one package, two classes' => [[ScriptedPackageContributor::class, OtherPackageContributor::class], 'laranail/settings is already registered as package [laranail/settings] '.ScriptedPackageContributor::class],
    'one class, twice' => [[ScriptedModuleContributor::class, ScriptedModuleContributor::class], 'Billing is already registered as module [Billing]'],
    'two applications' => [[ScriptedConfigurator::class, PrefixConfigurator::class], 'One settings configurator per application'],
]);

it('keeps useConfigurator() for the application configurator only', function (): void {
    expect(fn (): mixed => Settings::useConfigurator(ScriptedPackageContributor::class))
        ->toThrow(SettingsMisuse::class, 'register a package or module contributor with Settings::register()');
});

it('names exactly one package or module to exclude', function (): void {
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->exclude();

    expect(bootFailure([ScriptedConfigurator::class]))->toContain('exclude(): name exactly one package or one module.');
});
