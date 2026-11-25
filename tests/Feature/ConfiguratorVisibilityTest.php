<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Actions\DiagnoseSettings;
use Simtabi\Laranail\Settings\Configurator\SettingsSetup;
use Simtabi\Laranail\Settings\Configurator\StoreSetup;
use Simtabi\Laranail\Settings\Diagnostics\Finding;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Support\LeftoverEnvironment;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ConfiguratorProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ContributorsProvider;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\PrefixConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedConfigurator;
use Simtabi\Laranail\Settings\Tests\Fixtures\Configurator\ScriptedPackageContributor;

/** @return array<string, mixed> the command's JSON report */
function configuratorReport(string ...$options): array
{
    Artisan::call('laranail::settings.configurator', ['--json' => true, ...array_fill_keys($options, true)]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, array{key: string, value: mixed, source: string, owner: string|null}> */
function entriesByKey(array $report): array
{
    return array_column($report['entries'], null, 'key');
}

/** @return array<string, Finding> */
function configuratorFindings(): array
{
    $findings = array_filter(app(DiagnoseSettings::class)->handle(), static fn (Finding $finding): bool => str_starts_with($finding->name(), 'configurator'));

    return array_column(array_map(static fn (Finding $finding): array => [$finding->name(), $finding], $findings), 1, 0);
}

afterEach(function (): void {
    ConfiguratorProvider::$configurator = PrefixConfigurator::class;
    ContributorsProvider::$classes = [];
    ScriptedConfigurator::$configure = ScriptedPackageContributor::$configure = null;
    putenv('LARANAIL_SETTINGS_TABLE_PREFIX');
    unset($_SERVER['LARANAIL_SETTINGS_HTTP'], $_ENV['LARANAIL_SETTINGS_HTTP']);
});

it('shows the configurator and where each key comes from', function (): void {
    $this->bootWith([ConfiguratorProvider::class]);

    $report = configuratorReport();
    $entries = entriesByKey($report);

    expect($report['mode'])->toBe('loose')
        ->and($report['config_cached'])->toBeFalse()
        ->and($report['registrations'])->toHaveCount(1)
        ->and($report['registrations'][0]['class'])->toBe(PrefixConfigurator::class)
        ->and($report['registrations'][0]['site'])->toContain('ConfiguratorProvider.php:')
        ->and($report['registrations'][0]['status'])->toBe('applied')
        ->and($entries['tables.prefix'])->toBe(['key' => 'tables.prefix', 'value' => 'acme_', 'source' => 'configurator', 'owner' => 'app'])
        ->and($entries['stores.teams.layers']['source'])->toBe('configurator')
        ->and($entries['tables.audit'])->toBe(['key' => 'tables.audit', 'value' => 'settings_audit', 'source' => 'default', 'owner' => null])
        ->and($entries['layers.shipped.path']['source'])->toBe('config')
        ->and(count($entries))->toBeGreaterThan(60);
});

it('narrows by key, owner and scope', function (): void {
    ScriptedPackageContributor::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->store('billing', static fn (StoreSetup $store): StoreSetup => $store->layers('runtime'));
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->tables(prefix: 'acme_');
    ContributorsProvider::$classes = [ScriptedPackageContributor::class, ScriptedConfigurator::class];
    $this->bootWith([ContributorsProvider::class]);

    Artisan::call('laranail::settings.configurator', ['--json' => true, '--key' => 'tables']);
    $tables = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    Artisan::call('laranail::settings.configurator', ['--json' => true, '--owner' => 'laranail/settings', '--scope' => 'package']);
    $package = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_filter(array_column($tables['entries'], 'key'), static fn (string $key): bool => ! str_starts_with($key, 'tables.')))->toBe([])
        ->and(count($tables['entries']))->toBe(7)
        ->and(array_column($package['entries'], 'key'))->toBe(['stores.billing.layers'])
        ->and(array_column($package['registrations'], 'identity'))->toBe(['laranail/settings']);
});

it('redacts a value whose key could hold a secret', function (): void {
    config()->set('laranail.settings.alerts.webhook', sprintf('https://hooks.example.test/%s', TestCredentials::secret('webhook')));
    config()->set('laranail.settings.http.idempotency.token', TestCredentials::secret('idempotency'));

    $entries = entriesByKey(configuratorReport());

    expect($entries['alerts.webhook']['value'])->toBe('[redacted]')
        ->and($entries['http.idempotency.token']['value'])->toBe('[redacted]')
        ->and($entries['cache.prefix']['value'])->toBe('laranail-settings');
});

it('shows a refused or excluded contributor, and doctor warns about it', function (): void {
    ScriptedPackageContributor::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->store('billing', static fn (StoreSetup $store): StoreSetup => $store->layers('runtime'));
    ScriptedConfigurator::$configure = static fn (SettingsSetup $settings): SettingsSetup => $settings->mode(ConfiguratorMode::Strict);
    ContributorsProvider::$classes = [ScriptedPackageContributor::class, ScriptedConfigurator::class];
    $this->bootWith([ContributorsProvider::class]);

    $statuses = array_column(configuratorReport()['registrations'], 'status', 'identity');
    $findings = configuratorFindings();

    Artisan::call('laranail::settings.configurator');

    expect($statuses)->toBe(['laranail/settings' => 'refused', 'app' => 'applied'])
        ->and(Artisan::output())->toContain('Refused (strict)')
        ->and($findings['configurator']->run()->status->name)->toBe('Pass')
        ->and($findings['configurator: laranail/settings']->run()->status->name)->toBe('Warn')
        ->and($findings['configurator: laranail/settings']->run()->message)->toContain('refused in strict mode');
});

it('has doctor skip the configurator when nothing is registered', function (): void {
    expect(configuratorFindings()['configurator']->run()->status->name)->toBe('Skip');
});

it('warns about a leftover variable read straight from the environment, never through env()', function (): void {
    putenv('LARANAIL_SETTINGS_TABLE_PREFIX=acme_');
    $_SERVER['LARANAIL_SETTINGS_HTTP'] = 'true';

    Artisan::call('laranail::settings.configurator');
    $output = Artisan::output();

    expect(LeftoverEnvironment::find())->toBe(['LARANAIL_SETTINGS_TABLE_PREFIX' => 'tables(prefix:)', 'LARANAIL_SETTINGS_HTTP' => 'http()->enabled()'])
        ->and($output)->toContain('LARANAIL_SETTINGS_TABLE_PREFIX is still set in the environment')
        ->and($output)->toContain('tables(prefix:)')
        ->and(configuratorFindings()['configurator: environment']->run()->status->name)->toBe('Warn');

    putenv('LARANAIL_SETTINGS_TABLE_PREFIX');
    unset($_SERVER['LARANAIL_SETTINGS_HTTP']);

    expect(LeftoverEnvironment::find())->toBe([])
        ->and(configuratorFindings()['configurator: environment']->run()->status->name)->toBe('Pass');
});

it('reads no LARANAIL_SETTINGS_ variable in the shipped config, and still knows all 20 removed ones', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/settings.php');
    preg_match_all("/env\\('([A-Z_]+)'/", $config, $read);

    // The positive control: the scan finds the one env() read the file keeps, in a comment's example.
    expect($read[1])->toContain('SETTINGS_ALERTS_SLACK_WEBHOOK')
        ->and(array_filter($read[1], static fn (string $variable): bool => str_starts_with($variable, 'LARANAIL_SETTINGS_')))->toBe([])
        ->and(LeftoverEnvironment::REPLACEMENTS)->toHaveCount(20)
        ->and(array_filter(array_keys(LeftoverEnvironment::REPLACEMENTS), static fn (string $variable): bool => ! str_starts_with($variable, 'LARANAIL_SETTINGS_')))->toBe([]);
});
