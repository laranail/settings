<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\MakeStore;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/** @return list<string> */
function settingsMigrations(): array
{
    return glob(database_path('migrations') . '/*settings*.php') ?: [];
}

beforeEach(function (): void {
    File::delete(settingsMigrations());
    File::delete(config_path('laranail/settings.php'));
});

afterEach(function (): void {
    File::delete(settingsMigrations());
    File::delete(config_path('laranail/settings.php'));
});

it('publishes the config and the three migrations on install', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);

    $this->artisan('laranail::settings.install')->assertSuccessful();

    $names = array_map(static fn (string $path): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)) ?? '', settingsMigrations());
    sort($names);

    expect($names)->toBe(['create_settings_audit_table.php', 'create_settings_categories_table.php', 'create_settings_table.php']);
});

it('refuses to install over a table of the same name that is not its own (D-4)', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);
    app()->forgetScopedInstances();
    Schema::create('settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    expect(fn () => $this->artisan('laranail::settings.install')->run())->toThrow(InvalidConfiguration::class, DB::getDefaultConnection() . '.settings')
        ->and(settingsMigrations())->toBe([]);

    // The package's own tables are not foreign: installing again over them is fine.
    Schema::drop('settings');
    useDatabase();
    File::delete(settingsMigrations());

    $this->artisan('laranail::settings.install')->assertSuccessful();
});

it('generates a store\'s migration and prints its configuration', function (): void {
    $this->artisan('laranail::settings.make-store', ['store' => 'user_settings', '--owner' => ['user']])
        ->expectsOutputToContain('create_settings_user_settings_table.php')
        ->expectsOutputToContain("'owners' => ['user']")
        ->assertSuccessful();

    $files = settingsMigrations();

    expect($files)->toHaveCount(1)
        ->and(File::get($files[0]))->toContain("private string \$store = 'user_settings';")
        ->and(File::get($files[0]))->toContain('private ?string $pinnedConnection = null;');

    $this->artisan('laranail::settings.make-store', ['store' => 'user_settings'])->expectsOutputToContain('already exists')->assertFailed();
    $this->artisan('laranail::settings.make-store', ['store' => 'Bad-Name'])->assertFailed();

    // The generated migration really creates the table.
    useDatabase();
    $migration = require $files[0];
    $migration->up();

    expect(Schema::hasColumns('user_settings', ['scope', 'key', 'locale', 'version']))->toBeTrue();

    $migration->down();

    expect(Schema::hasTable('user_settings'))->toBeFalse();
});

it('generates the categories and audit tables too for a store on its own connection', function (): void {
    $generated = app(MakeStore::class)->handle(new StoreName('archive'), 'archive_db', ['ledger']);

    expect($generated->migrations)->toHaveCount(3)
        ->and(File::get($generated->migrations[0]))->toContain("private ?string \$pinnedConnection = 'archive_db';")
        ->and(File::get($generated->migrations[2]))->toContain("private string \$store = 'archive';")
        ->and($generated->config)->toContain("'database_archive_db' => ['driver' => 'database', 'connection' => 'archive_db']")
        ->and(fn () => app(MakeStore::class)->handle(new StoreName('other'), 'bad connection!'))->toThrow(InvalidConfiguration::class)
        ->and(fn () => app(MakeStore::class)->handle(new StoreName('other'), null, ['Bad Alias']))->toThrow(InvalidIdentifier::class);
});

it('reports readiness, schema drift and rows the database cannot guard', function (): void {
    useDatabase([
        'stores.settings.owners' => ['user'],
        'stores.files'           => ['layers' => ['runtime']],
    ]);

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain(sprintf('Table [settings] on [%s] is ready.', DB::getDefaultConnection()))
        ->expectsOutputToContain('No schema drift.')
        ->expectsOutputToContain('Every scope uses a listed alias.')
        ->expectsOutputToContain('No database layer')
        ->assertSuccessful();

    $now = now();
    $row = static fn (string $scope, string $uid, array $extra = []): array => $extra + ['scope' => $scope, 'key' => 'k', 'locale' => '', 'group' => 'k', 'uid' => $uid, 'value' => '1', 'version' => 1, 'created_at' => $now, 'updated_at' => $now];
    DB::table('settings')->insert($row('team:1', str_repeat('A', 26)));
    // A cross-store link only an import or manual SQL could make, past the foreign key.
    Schema::withoutForeignKeyConstraints(static fn () => DB::table('settings')->insert($row('global', str_repeat('B', 26), ['key' => 'c', 'category_id' => 9, 'category_store' => 'other'])));

    $this->artisan('laranail::settings.doctor')
        ->expectsOutputToContain('aliases the store does not list')
        ->expectsOutputToContain('1 row(s) point at a category of another store.')
        ->assertFailed();

    DB::table('settings')->insert($row('App\Models\User:1', str_repeat('C', 26)));
    Schema::table('settings_audit', static function (Blueprint $table): void {
        $table->dropColumn('reason');
    });

    // One JSON document is one write, so read it whole.
    expect(Artisan::call('laranail::settings.doctor', ['--json' => true]))->toBe(1);

    $report = json_decode(Artisan::output(), true);

    expect($report['status'] ?? null)->toBe('degraded')
        ->and(json_encode($report))->toContain('instead of morph aliases')
        ->and(json_encode($report))->toContain('settings_audit.reason');
});

it('fails readiness before the migrations have run', function (): void {
    config()->set('laranail.settings.stores.settings.layers', ['database']);
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.doctor')->expectsOutputToContain('is not ready: run the migrations')->assertFailed();

    config()->set('laranail.settings.stores.settings.bootstrap', true);
    app()->forgetScopedInstances();

    $this->artisan('laranail::settings.doctor')->expectsOutputToContain('bootstrap mode may be skipping it')->assertSuccessful();
});
