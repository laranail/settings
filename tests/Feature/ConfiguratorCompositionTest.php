<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Exceptions\PackageBootException;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorResult;
use Simtabi\Laranail\Settings\Actions\DiagnoseSettings;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Diagnostics\Finding;
use Simtabi\Laranail\Settings\Enums\Stage;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ContributorsProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedPackageContributor;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\TaggingDecorator;

/** Boots with the application configurator, and the package contributor first when given. */
function bootComposition(Closure $app, ?Closure $package = null): void
{
    ScriptedConfigurator::$configure = $app;
    ScriptedPackageContributor::$configure = $package;
    ContributorsProvider::$classes = $package instanceof Closure ? [ScriptedPackageContributor::class, ScriptedConfigurator::class] : [ScriptedConfigurator::class];
    test()->bootWith([ContributorsProvider::class]);
}

function tagged(string $tag): Closure
{
    return static fn (SettingsSetup $settings): SettingsSetup => $settings->decorate(static fn (Settings $inner): TaggingDecorator => new TaggingDecorator($inner, $tag));
}

afterEach(function (): void {
    ContributorsProvider::$classes = [];
    ScriptedConfigurator::$configure = ScriptedPackageContributor::$configure = null;
    TaggingDecorator::$calls = [];
});

it('keeps a decorator on every handle a call derives', function (): void {
    bootComposition(tagged('app'));

    $handle = settings()->store('settings')->locale('en')->asSystem('test')->because('a reason');
    $handle->get('site.name');

    expect(settings())->toBeInstanceOf(TaggingDecorator::class)
        ->and($handle)->toBeInstanceOf(TaggingDecorator::class)
        ->and(TaggingDecorator::$calls)->toBe(['app']);
});

it('wraps packages first and the application outermost, every decorator kept', function (): void {
    bootComposition(tagged('app'), tagged('package'));

    settings()->store('settings')->get('site.name');

    $outer = settings();

    expect($outer)->toBeInstanceOf(TaggingDecorator::class)
        ->and($outer instanceof TaggingDecorator ? $outer->tag : null)->toBe('app')
        ->and($outer instanceof TaggingDecorator ? $outer->inner() : null)->toBeInstanceOf(TaggingDecorator::class)
        ->and(TaggingDecorator::$calls)->toBe(['app', 'package']);
});

it('refuses a decorator that does not extend SettingsDecorator, saying why', function (): void {
    bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings->decorate(static fn (Settings $inner): Settings => $inner));

    expect(fn (): Settings => app(Settings::class))->toThrow(InvalidConfiguration::class, 'a decorator must extend');
});

it('runs write stages on the plain value before validation, and read stages after decryption', function (): void {
    $seen = new ArrayObject;

    bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->define('api.token', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition->ofType('string')->encrypted()->rules(['max:8']))
        ->pipe(Stage::BeforeWrite, static function (mixed $value, Key $key) use ($seen): mixed {
            $seen[] = ['write', $key->value, $value];

            return is_string($value) ? strtoupper($value) : $value;
        })
        ->pipe(Stage::AfterRead, static function (mixed $value, Key $key) use ($seen): mixed {
            $seen[] = ['read', $key->value, $value];

            return is_string($value) ? $value.'!' : $value;
        }));

    settings()->set('api.token', 'abc');

    expect(settings()->get('api.token'))->toBe('ABC!')
        ->and($seen->getArrayCopy())->toBe([['write', 'api.token', 'abc'], ['read', 'api.token', 'ABC']])
        ->and(fn () => settings()->set('api.token', 'too long a token'))->toThrow(InvalidSettingValue::class);
});

it('validates what a write stage returns, so a stage cannot get past the rules', function (): void {
    bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->define('site.code', static fn (DefinitionBuilder $definition): DefinitionBuilder => $definition->ofType('string')->rules(['max:3']))
        ->pipe(Stage::BeforeWrite, static fn (mixed $value): string => $value.'-and-more'));

    expect(fn () => settings()->set('site.code', 'abc'))->toThrow(InvalidSettingValue::class);
});

it('runs stages from every owner, packages first', function (): void {
    bootComposition(
        static fn (SettingsSetup $settings): SettingsSetup => $settings->pipe(Stage::BeforeWrite, static fn (mixed $value): string => $value.'-app'),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->pipe(Stage::BeforeWrite, static fn (mixed $value): string => $value.'-package'),
    );

    settings()->set('site.name', 'acme');

    expect(settings()->get('site.name'))->toBe('acme-package-app');
});

it('listens to the package events it is given', function (): void {
    $saved = new ArrayObject;

    bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings
        ->on(SettingSaved::class, static function (SettingSaved $event) use ($saved): void {
            $saved[] = $event->key;
        }));

    settings()->set('site.name', 'Acme');

    expect($saved->getArrayCopy())->toBe(['site.name'])
        ->and(fn () => bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings->on('NoSuchEvent', static fn (): null => null)))->toThrow(PackageBootException::class);
});

it('adds macros to every handle, decorated or not, and refuses a name that is already a method', function (): void {
    bootComposition(static fn (SettingsSetup $settings): SettingsSetup => tagged('app')($settings)
        ->macro('siteName', function (): mixed {
            /** @var SettingsManager $this */
            return $this->get('site.name', 'unnamed');
        })
        ->mixin(new class
        {
            public function shout(): Closure
            {
                return fn (string $key): string => strtoupper((string) $this->get($key, ''));
            }
        }));

    settings()->set('site.name', 'Acme');

    expect(settings()->siteName())->toBe('Acme')
        ->and(settings()->store('settings')->shout('site.name'))->toBe('ACME')
        ->and(SettingsManager::hasMacro('siteName'))->toBeTrue();

    try {
        bootComposition(static fn (SettingsSetup $settings): SettingsSetup => $settings->macro('get', static fn (): null => null));
        $this->fail('A macro named get was accepted.');
    } catch (PackageBootException $exception) {
        expect($exception->getPrevious()?->getMessage())->toContain('macro() (laranail.settings.macros.get)');
    }
});

it('flushes macros between tests, since they are static', function (): void {
    // Runs after the test above, whose macros the test case flushed in its tearDown.
    expect(SettingsManager::hasMacro('siteName'))->toBeFalse()
        ->and(SettingsManager::hasMacro('shout'))->toBeFalse();
});

it('refuses one macro name from two owners', function (): void {
    try {
        bootComposition(
            static fn (SettingsSetup $settings): SettingsSetup => $settings->macro('siteName', static fn (): string => 'app'),
            static fn (SettingsSetup $settings): SettingsSetup => $settings->macro('siteName', static fn (): string => 'package'),
        );
        $this->fail('The second macro was accepted.');
    } catch (PackageBootException $exception) {
        expect($exception->getPrevious()?->getMessage())->toContain('laranail.settings.macros.siteName is owned by the package [laranail/settings]');
    }
});

it('runs doctor checks from every owner after its own', function (): void {
    bootComposition(
        static fn (SettingsSetup $settings): SettingsSetup => $settings->doctorCheck(new Finding('app: billing', 'Billing is wired', DoctorResult::pass('wired'))),
        static fn (SettingsSetup $settings): SettingsSetup => $settings->doctorCheck(new Finding('package: billing', 'The package is wired', DoctorResult::pass('wired'))),
    );

    $names = array_map(static fn (Finding $finding): string => $finding->name(), app(DiagnoseSettings::class)->handle());

    expect(array_slice($names, -2))->toBe(['package: billing', 'app: billing']);
});
