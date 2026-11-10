<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/** Insert a definition row edited at runtime (not is_system). */
function runtimeDefinition(string $key, array $columns = []): void
{
    DB::table('settings_definitions')->insert($columns + [
        'store'      => 'settings',
        'slug'       => str_replace('.', '-', $key),
        'key'        => $key,
        'group'      => explode('.', $key)[0],
        'is_system'  => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('syncs code and config definitions in as system rows and removes stale ones', function (): void {
    config()->set('laranail.settings.definitions', ['site.name' => ['type' => 'string', 'default' => 'Acme', 'label' => 'Site name']] + config('laranail.settings.definitions'));
    useDatabase(['definitions.table' => true]);
    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    app(DefinitionRegistry::class)->define('mail.password')->ofType('string')->withDefault(TestCredentials::password())->encrypted()->inCategory('mail')->register();
    DB::table('settings_definitions')->insert(['store' => 'settings', 'slug' => 'gone', 'key' => 'gone', 'group' => 'gone', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);

    $report = app(SyncDefinitions::class)->handle();
    $password = DB::table('settings_definitions')->where('key', 'mail.password')->first();

    expect($report)->toMatchArray(['synced' => 2, 'removed' => 1, 'conflicts' => []])
        ->and(DB::table('settings_definitions')->where('key', 'site.name')->value('label'))->toBe('Site name')
        ->and(DB::table('settings_definitions')->where('key', 'site.name')->value('default'))->toBe('"Acme"')
        ->and((bool) $password?->is_system)->toBeTrue()
        ->and($password?->default)->toBeNull()
        ->and($password?->category_id)->not->toBeNull();

    // Idempotent.
    expect(app(SyncDefinitions::class)->handle())->toMatchArray(['synced' => 2, 'removed' => 0]);
});

it('reports a runtime row that shares a key with code, and leaves it alone', function (): void {
    useDatabase(['definitions.table' => true]);
    app(DefinitionRegistry::class)->define('site.name')->ofType('string')->register();
    runtimeDefinition('site.name', ['label' => 'Edited']);

    expect(app(SyncDefinitions::class)->handle()['conflicts'])->toBe(['settings.site.name'])
        ->and(DB::table('settings_definitions')->value('label'))->toBe('Edited');
});

it('answers for keys no code covers from runtime rows, never from system rows', function (): void {
    useDatabase(['definitions.table' => true]);
    runtimeDefinition('billing.currency', ['type' => 'string', 'default' => '"EUR"', 'rules' => '["required","in:EUR,USD","exists:currencies,code"]', 'label' => 'Currency']);
    DB::table('settings_definitions')->insert(['store' => 'settings', 'slug' => 'site-name', 'key' => 'site.name', 'group' => 'site', 'default' => '"Stale"', 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);

    expect(settings()->get('billing.currency'))->toBe('EUR')
        ->and(settings()->has('site.name'))->toBeFalse()
        ->and(fn () => settings()->set('billing.currency', 'GBP'))->toThrow(InvalidSettingValue::class);

    // `exists` is not an allowed rule, so it was dropped rather than run against a table.
    settings()->set('billing.currency', 'USD');

    expect(settings()->get('billing.currency'))->toBe('USD');
});

it('never lets a table row loosen a coded definition', function (): void {
    useDatabase(['definitions.table' => true]);
    app(DefinitionRegistry::class)->define('mail.password')->ofType('string')->encrypted()->register();
    runtimeDefinition('mail.password', ['is_encrypted' => false, 'is_public' => true]);

    settings()->set('mail.password', TestCredentials::secret());

    expect((bool) DB::table('settings')->value('is_encrypted'))->toBeTrue();
});

it('refuses a row whose type is not a registered caster', function (): void {
    useDatabase(['definitions.table' => true]);
    runtimeDefinition('x.y', ['type' => 'App\\Evil']);

    expect(fn (): mixed => settings()->get('x.y'))->toThrow(InvalidDefinition::class, 'unknown type');
});

it('reads JSON labels in json translations mode', function (): void {
    useDatabase(['definitions.table' => true, 'translations.mode' => 'json']);
    app(DefinitionRegistry::class)->define('site.name')->labelled('Site name')->register();
    runtimeDefinition('site.tagline', ['label' => '{"":"Tagline","fr":"Slogan"}', 'default' => '"Hi"']);

    app(SyncDefinitions::class)->handle();

    expect(json_decode((string) DB::table('settings_definitions')->where('key', 'site.name')->value('label'), true))->toBe(['' => 'Site name'])
        ->and(settings()->get('site.tagline'))->toBe('Hi');
});

it('reads no table definitions when `database` is not a source, and validates sources', function (): void {
    useDatabase(['definitions.table' => true, 'definitions.sources' => ['code', 'config']]);
    runtimeDefinition('billing.currency', ['default' => '"EUR"']);

    expect(settings()->has('billing.currency'))->toBeFalse();

    config()->set('laranail.settings.definitions.sources', ['code', 'yaml']);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->get('x'))->toThrow(InvalidConfiguration::class, 'definitions.sources');
});

it('skips config definitions when `config` is not a source, and never reads option names as keys', function (): void {
    config()->set('laranail.settings.definitions', ['site.name' => ['default' => 'Acme'], 'sources' => ['code'], 'table' => false]);
    app()->forgetInstance(DefinitionRegistry::class);
    app()->forgetScopedInstances();

    expect(settings()->has('site.name'))->toBeFalse()
        ->and(settings()->has('table'))->toBeFalse();

    // Positive control: with `config` back, the same map answers, and `table` is still not a key.
    config()->set('laranail.settings.definitions.sources', ['code', 'config']);
    app()->forgetInstance(DefinitionRegistry::class);
    app()->forgetScopedInstances();

    expect(settings()->get('site.name'))->toBe('Acme')
        ->and(settings()->has('table'))->toBeFalse();
});

it('needs manage_definitions to sync with an actor, is audited, and runs from the command and doctor --fix', function (): void {
    useDatabase(['definitions.table' => true]);
    $audit = auditInMemory();

    expect(fn () => app(SyncDefinitions::class)->handle(Actor::user(user())))->toThrow(AccessDenied::class);

    allowGates(Ability::ManageDefinitions);
    app(SyncDefinitions::class)->handle(Actor::user(user()));

    expect(collect($audit->entries())->where('action', AuditAction::ManageDefinitions)->count())->toBe(2);

    $this->artisan('laranail::settings.sync-definitions')->expectsOutputToContain('Synced 0 definition(s)')->assertSuccessful();
    $this->artisan('laranail::settings.doctor', ['--fix' => true])->expectsOutputToContain('Synced 0 definition(s)');
});

it('skips stores without the table when syncing', function (): void {
    useDatabase();

    expect(app(SyncDefinitions::class)->handle()['skipped'])->toBe(['settings']);
});

it('reports skipped stores and conflicts from the command, and fails when syncing fails', function (): void {
    useDatabase(['definitions.table' => true, 'stores.files' => ['layers' => ['runtime']]]);
    app(DefinitionRegistry::class)->define('site.name')->register();
    runtimeDefinition('site.name');

    $this->artisan('laranail::settings.sync-definitions')
        ->expectsOutputToContain('Store [files] skipped')
        ->expectsOutputToContain('[settings.site.name] is defined in code and edited in the table')
        ->assertSuccessful();

    Schema::drop('settings_definitions');
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.sync-definitions')->assertFailed();
    $this->artisan('laranail::settings.doctor', ['--fix' => true])->assertFailed();
});
