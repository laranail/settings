<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * Stores, layers and definitions saved through the API, validated like configuration,
 * gated and audited, merged in configuration.sources order, read lazily, and an invalid row is
 * skipped and reported rather than breaking the application.
 */

beforeEach(function (): void {
    useDatabase(['configuration.persisted' => true, 'definitions.sources' => ['code', 'config', 'database']]);
});

it('always creates the persisted configuration table, and saves to it only when the switch is on', function (): void {
    expect(Schema::hasTable('settings_stores'))->toBeTrue();

    useDatabase(['configuration.persisted' => false]);

    expect(Schema::hasTable('settings_stores'))->toBeTrue()
        ->and(fn () => settings()->persistedConfiguration()->saveStore('extra', ['layers' => ['runtime']]))->toThrow(InvalidConfiguration::class, 'persisted configuration is off');
});

it('saves a store and a layer that settings then use, and audits it', function (): void {
    $editor = settings()->persistedConfiguration();
    $editor->saveLayer('scratch', ['driver' => 'array']);
    $editor->saveStore('extra', ['layers' => ['scratch'], 'owners' => ['team']]);

    settings()->store('extra')->set('feature.on', true);

    expect(settings()->store('extra')->get('feature.on'))->toBeTrue()
        ->and(array_map(static fn (ConfigurationEntry $entry): string => $entry->kind . ':' . $entry->name, $editor->entries()))->toBe(['layer:scratch', 'store:extra'])
        ->and(DB::table('settings_audit')->where('action', AuditAction::ManageStores->value)->count())->toBe(2)
        ->and($editor->deleteStore('extra'))->toBeTrue()
        ->and($editor->deleteStore('extra'))->toBeFalse()
        ->and($editor->deleteLayer('scratch'))->toBeTrue()
        ->and($editor->deleteLayer('scratch'))->toBeFalse()
        ->and(fn (): mixed => settings()->store('extra')->get('feature.on'))->toThrow(UnknownStore::class);
});

it('validates an entry before saving it, and refuses re-layering a configured store', function (): void {
    $editor = settings()->persistedConfiguration();

    expect(fn () => $editor->saveStore('extra', ['layers' => ['nope']]))->toThrow(InvalidConfiguration::class, 'layer [nope] is not defined')
        ->and(fn () => $editor->saveStore('settings', ['layers' => ['runtime']]))->toThrow(InvalidConfiguration::class, 'change them there')
        ->and(fn () => $editor->saveStore('Bad Name', ['layers' => ['runtime']]))->toThrow(InvalidConfiguration::class)
        ->and(DB::table('settings_stores')->count())->toBe(0);
});

it('never persists a php or config layer, which would run a file or read configuration on the server', function (): void {
    $file = sys_get_temp_dir() . '/laranail-settings-persisted-' . bin2hex(random_bytes(6));
    $marker = $file . '.ran';
    file_put_contents($file . '.php', "<?php file_put_contents('" . $marker . "', 'ran'); return ['a' => 1];");
    $editor = settings()->persistedConfiguration();

    expect(fn () => $editor->saveLayer('evil', ['driver' => 'php', 'path' => $file . '.php']))->toThrow(InvalidConfiguration::class, 'only be defined in the configuration file')
        ->and(fn () => $editor->saveLayer('shipped', ['driver' => 'php', 'path' => $file . '.php']))->toThrow(InvalidConfiguration::class, 'only be defined in the configuration file')
        ->and(fn () => $editor->saveLayer('secrets', ['driver' => 'config', 'prefix' => 'app']))->toThrow(InvalidConfiguration::class, 'only be defined in the configuration file')
        ->and(DB::table('settings_stores')->count())->toBe(0);

    // A row written to the table directly is skipped the same way, and its file is never required.
    DB::table('settings_stores')->insert([
        ['id' => '01J00000000000000000000001', 'kind' => 'layer', 'name' => 'evil', 'config' => json_encode(['driver' => 'php', 'path' => $file . '.php']), 'created_at' => now(), 'updated_at' => now()],
        ['id' => '01J00000000000000000000002', 'kind' => 'store', 'name' => 'evil', 'config' => '{"layers":["evil"]}', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->store('evil')->get('a'))->toThrow(UnknownStore::class)
        ->and(is_file($marker))->toBeFalse();
});

it('only adds layers: a persisted layer never redefines one the configuration file defines', function (): void {
    expect(fn () => settings()->persistedConfiguration()->saveLayer('database', ['driver' => 'array']))->toThrow(InvalidConfiguration::class, 'would redefine a layer')
        ->and(DB::table('settings_stores')->count())->toBe(0);

    settings()->set('site.name', 'Acme');
    // A row written to the table directly is skipped, and the store keeps the file's database layer.
    DB::table('settings_stores')->insert(['id' => '01J00000000000000000000003', 'kind' => 'layer', 'name' => 'database', 'config' => '{"driver":"array"}', 'created_at' => now(), 'updated_at' => now()]);
    app()->forgetScopedInstances();

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->persistedConfiguration()->entries())->toBe([]);

    Artisan::call('laranail::settings.doctor', ['--json' => true]);

    expect(Artisan::output())->toContain('layer [database]: Persisted layer [database] would redefine');
});

it('skips and reports an invalid row instead of failing', function (): void {
    DB::table('settings_stores')->insert([
        ['id' => '01J00000000000000000000001', 'kind' => 'store', 'name' => 'broken', 'config' => '{"layers":["missing"]}', 'created_at' => now(), 'updated_at' => now()],
        ['id' => '01J00000000000000000000002', 'kind' => 'store', 'name' => 'fine', 'config' => '{"layers":["runtime"]}', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app()->forgetScopedInstances();

    settings()->store('fine')->set('a', 1);
    Artisan::call('laranail::settings.doctor', ['--json' => true]);

    expect(settings()->store('fine')->get('a'))->toBe(1)
        ->and(fn (): mixed => settings()->store('broken')->get('a'))->toThrow(UnknownStore::class)
        ->and(Artisan::output())->toContain('Persisted entries skipped as invalid: store [broken]');
});

it('merges persisted entries in configuration.sources order', function (): void {
    config()->set('laranail.settings.stores.extra', ['layers' => ['runtime'], 'strict' => true]);
    app()->forgetScopedInstances();
    settings()->persistedConfiguration()->saveStore('extra', ['layers' => ['runtime'], 'strict' => false]);

    expect(settings()->store('extra')->get('anything'))->toBeNull();

    config()->set('laranail.settings.configuration.sources', ['database', 'config']);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->store('extra')->get('anything'))->toThrow(UnknownSetting::class, 'strict');

    config()->set('laranail.settings.configuration.sources', ['config', 'nowhere']);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('x'))->toThrow(InvalidConfiguration::class, 'configuration.sources');
});

it('saves and deletes definitions in the definitions table, never over code', function (): void {
    $editor = settings()->persistedConfiguration();
    $definition = $editor->saveDefinition('site.theme', ['type' => 'string', 'default' => 'light', 'rules' => ['in:light,dark'], 'label' => 'Theme']);

    expect($definition->label)->toBe('Theme')
        ->and(settings()->get('site.theme'))->toBe('light')
        ->and(fn () => settings()->set('site.theme', 'blue'))->toThrow(InvalidSettingValue::class);

    app(DefinitionRegistry::class)->define('site.name')->register();

    expect(fn (): Definition => $editor->saveDefinition('site.name', []))->toThrow(InvalidDefinition::class, 'code or configuration')
        ->and(fn (): Definition => $editor->saveDefinition('site.x', ['type' => 'nope']))->toThrow(InvalidDefinition::class, 'unknown type')
        ->and($editor->deleteDefinition('site.theme'))->toBeTrue()
        ->and(settings()->get('site.theme'))->toBeNull();
});

it('needs manage_stores and manage_definitions from an actor', function (): void {
    $editor = settings()->as(user())->persistedConfiguration();

    expect(fn () => $editor->saveStore('extra', ['layers' => ['runtime']]))->toThrow(AccessDenied::class, 'manage_stores')
        ->and(fn (): array => $editor->entries())->toThrow(AccessDenied::class)
        ->and(fn (): Definition => $editor->saveDefinition('a.b', []))->toThrow(AccessDenied::class, 'manage_definitions');

    allowGates(Ability::ManageDefinitions);
    config()->set('laranail.settings.verification.driver', 'none');
    app()->forgetScopedInstances();

    expect(settings()->as(user())->persistedConfiguration()->saveDefinition('a.b', [])->key->value)->toBe('a.b');
});
