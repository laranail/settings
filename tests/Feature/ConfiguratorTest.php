<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Exceptions;
use Simtabi\Laranail\Settings\Facades\Settings;
use Simtabi\Laranail\Settings\Configurator\AppliedSetup;
use Simtabi\Laranail\Settings\Exceptions\SettingsMisuse;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Package\Tools\Exceptions\PackageBootException;
use Simtabi\Laranail\Settings\Configurator\ConfiguratorRegistration;
use Simtabi\Laranail\Settings\Services\Configurator\ConfiguratorRegistry;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\GreetingProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\PrefixConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\FailingConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\KeepingConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ConfiguratorProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\GreetingConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\SecondConfiguratorProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\BootTimeConfiguratorProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\DeferredConfiguratorProvider;

afterEach(function (): void {
    ConfiguratorProvider::$configurator = PrefixConfigurator::class;
    KeepingConfigurator::$setup = null;
});

it('writes what the configurator sets into config while the application boots', function (): void {
    $this->bootWith([ConfiguratorProvider::class]);

    expect(config('laranail.settings.tables.prefix'))->toBe('acme_')
        ->and(config('laranail.settings.tables.audit'))->toBe('settings_audit')
        ->and(config('laranail.settings.stores.teams'))->toBe(['layers' => ['runtime'], 'owners' => ['team'], 'inherits' => true])
        ->and(config('laranail.settings.cache.ttl'))->toBe(600)
        ->and(config('laranail.settings.cache.stale'))->toBe(300);
});

it('records where the configurator was registered and what it set', function (): void {
    $this->bootWith([ConfiguratorProvider::class]);

    $registration = Settings::configuratorRegistration();
    $applied = app(ConfiguratorRegistry::class)->applied();

    expect($registration?->class)->toBe(PrefixConfigurator::class)
        ->and($registration?->classFile)->toBe(new ReflectionClass(PrefixConfigurator::class)->getFileName())
        ->and($registration?->file)->toEndWith('tests/Fixtures/Configurator/ConfiguratorProvider.php')
        ->and($registration?->line)->toBeGreaterThan(0)
        ->and($applied)->toBeInstanceOf(AppliedSetup::class)
        ->and($applied?->written)->toBeTrue()
        ->and($applied?->value('tables.prefix'))->toBe('acme_')
        ->and($applied?->has('tables.audit'))->toBeFalse();
});

it('has no registration and changes nothing without a configurator', function (): void {
    expect(Settings::configuratorRegistration())->toBeNull()
        ->and(app(ConfiguratorRegistry::class)->applied())->toBeNull();
});

it('holds a registration made before the package provider registers', function (): void {
    $this->bootWith([], [ConfiguratorProvider::class]);

    expect(Settings::configuratorRegistration()?->class)->toBe(PrefixConfigurator::class)
        ->and(config('laranail.settings.tables.prefix'))->toBe('acme_');
});

it('builds the configurator once every provider has registered, so it may use a later binding', function (): void {
    ConfiguratorProvider::$configurator = GreetingConfigurator::class;

    $this->bootWith([ConfiguratorProvider::class, GreetingProvider::class]);

    expect(config('laranail.settings.default'))->toBe('greetings');
});

it('refuses a second configurator, naming both registration sites', function (): void {
    expect(fn () => $this->bootWith([ConfiguratorProvider::class, SecondConfiguratorProvider::class]))
        ->toThrow(SettingsMisuse::class, 'One settings configurator per application');

    $registry = new ConfiguratorRegistry;
    $reported = [];
    $report = static function (Throwable $exception) use (&$reported): void {
        $reported[] = $exception;
    };
    $registry->register(PrefixConfigurator::class, '/app/Providers/AppServiceProvider.php', 21, $report);

    try {
        $registry->register(GreetingConfigurator::class, '/app/Providers/OtherProvider.php', 9, $report);
        $this->fail('The second registration was accepted.');
    } catch (SettingsMisuse $exception) {
        expect($exception->getMessage())->toContain(PrefixConfigurator::class . ' (registered at /app/Providers/AppServiceProvider.php:21)')
            ->and($exception->getMessage())->toContain(GreetingConfigurator::class . ' (registered at /app/Providers/OtherProvider.php:9)')
            ->and($reported)->toBe([$exception])
            ->and($registry->registration()?->class)->toBe(PrefixConfigurator::class);
    }
});

it('refuses a class that is not a configurator', function (): void {
    expect(fn (): ConfiguratorRegistration => Settings::useConfigurator(stdClass::class))->toThrow(SettingsMisuse::class, 'must implement exactly one of');
});

it('refuses a registration from boot(), naming the call site', function (): void {
    expect(fn () => $this->bootWith([BootTimeConfiguratorProvider::class]))
        ->toThrow(SettingsMisuse::class, 'BootTimeConfiguratorProvider.php');
});

it('refuses a registration from a deferred provider', function (): void {
    expect(fn () => $this->bootWith([DeferredConfiguratorProvider::class]))
        ->toThrow(SettingsMisuse::class, 'DeferredConfiguratorProvider.php:16) was registered after the application started booting');
});

it('refuses and reports a change to the setup after the application has booted', function (): void {
    ConfiguratorProvider::$configurator = KeepingConfigurator::class;
    $this->bootWith([ConfiguratorProvider::class]);
    Exceptions::fake();

    $setup = KeepingConfigurator::$setup;

    expect($setup)->toBeInstanceOf(SettingsSetup::class)
        ->and(fn (): ?SettingsSetup => $setup?->defaultStore('late'))->toThrow(SettingsMisuse::class, 'is sealed')
        ->and(config('laranail.settings.default'))->toBe('settings');

    Exceptions::assertReported(SettingsMisuse::class);
});

it('stops the boot when the configurator sets an invalid value, naming the method, the path and the configurator', function (): void {
    ConfiguratorProvider::$configurator = FailingConfigurator::class;

    try {
        $this->bootWith([ConfiguratorProvider::class]);
        $this->fail('The boot went on.');
    } catch (PackageBootException $exception) {
        expect($exception->getMessage())->toContain(FailingConfigurator::class)
            ->and($exception->getPrevious())->toBeInstanceOf(InvalidConfiguration::class)
            ->and($exception->getPrevious()?->getMessage())->toContain('tables() (laranail.settings.tables.prefix)');
    }
});

it('runs the configurator but leaves cached config as it was cached', function (): void {
    $registry = new ConfiguratorRegistry;
    $registry->register(PrefixConfigurator::class, __FILE__, __LINE__, static function (): void {});
    $written = [];

    $applied = $registry->apply(app(), true, static function (string $path, mixed $value) use (&$written): void {
        $written[$path] = $value;
    }, static function (): void {});

    expect($written)->toBe([])
        ->and($applied?->written)->toBeFalse()
        ->and($applied?->value('tables.prefix'))->toBe('acme_');
});

it('keeps each application its own configurator across two lifecycles in one process', function (): void {
    $this->bootWith([ConfiguratorProvider::class]);
    expect(config('laranail.settings.tables.prefix'))->toBe('acme_');

    $this->bootWith([]);

    expect(config('laranail.settings.tables.prefix'))->toBe('laranail_')
        ->and(Settings::configuratorRegistration())->toBeNull();
});

it('names its tables in the migration with the configurator prefix', function (): void {
    $this->bootWith([ConfiguratorProvider::class]);

    useDatabase();

    expect(Schema::hasTable('acme_settings'))->toBeTrue()
        ->and(Schema::hasTable('acme_settings_audit'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeFalse();
});

it('validates each value where the configurator sets it', function (Closure $set, string $message): void {
    ConfiguratorProvider::$configurator = KeepingConfigurator::class;
    $registry = new ConfiguratorRegistry;
    $registry->register(KeepingConfigurator::class, __FILE__, __LINE__, static function (): void {});
    $registry->apply(app(), true, static function (): void {}, static function (): void {});

    expect(fn () => $set(KeepingConfigurator::$setup))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'store name'   => [static fn (SettingsSetup $setup): SettingsSetup => $setup->defaultStore('Main'), 'defaultStore() (laranail.settings.default)'],
    'promote'      => [static fn (SettingsSetup $setup): SettingsSetup => $setup->promote('sometimes'), 'one of manual, auto'],
    'table name'   => [static fn (SettingsSetup $setup): SettingsSetup => $setup->tables(audit: '1audit'), 'tables() (laranail.settings.tables.audit)'],
    'store layer'  => [static fn (SettingsSetup $setup): SettingsSetup => $setup->store('teams', static fn ($store) => $store->layers('Bad')), 'store()->layers()'],
    'cache ttl'    => [static fn (SettingsSetup $setup): SettingsSetup => $setup->cache(static fn ($cache) => $cache->ttl(0)), 'cache()->ttl() (laranail.settings.cache.ttl)'],
    'log level'    => [static fn (SettingsSetup $setup): SettingsSetup => $setup->logging(static fn ($logging) => $logging->level('loud')), 'logging()->level()'],
    'layer disk'   => [static fn (SettingsSetup $setup): SettingsSetup => $setup->layer('runtime', static fn ($layer) => $layer->disk(' ')), 'layer()->disk()'],
    'http prefix'  => [static fn (SettingsSetup $setup): SettingsSetup => $setup->http(static fn ($http) => $http->prefix('/')), 'http()->prefix()'],
    'approvals'    => [static fn (SettingsSetup $setup): SettingsSetup => $setup->approvals(static fn ($approvals) => $approvals->minApprovals(0)), 'approvals()->minApprovals()'],
    'value size'   => [static fn (SettingsSetup $setup): SettingsSetup => $setup->values(static fn ($values) => $values->maxBytes(0)), 'values()->maxBytes()'],
    'history days' => [static fn (SettingsSetup $setup): SettingsSetup => $setup->audit(static fn ($audit) => $audit->history(0)), 'audit()->history()'],
]);
