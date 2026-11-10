<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Database\SchemaInspector;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;

/*
 * The package's one migration: every table, in dependency order, for every recorded database
 * store, and a down() that leaves nothing behind.
 */

/** The migration, freshly required (each require builds a new instance). */
function packageMigration(): Migration
{
    return require dirname(__DIR__, 3) . '/database/migrations/create_laranail_settings_tables.php';
}

it('creates every table of every database store in one run, and drops them all again', function (): void {
    $other = sys_get_temp_dir() . '/laranail-settings-archive-' . bin2hex(random_bytes(4)) . '.sqlite';
    touch($other);
    config()->set('database.connections.archive_db', ['driver' => 'sqlite', 'database' => $other, 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('laranail.settings.layers.archive', ['driver' => 'database', 'connection' => 'archive_db']);

    useDatabase([
        'tables.prefix'  => 'app_',
        'stores.billing' => ['layers' => ['database'], 'table' => 'billing_values'],
        'stores.users'   => ['layers' => ['database'], 'owners' => ['user'], 'mode' => 'dedicated', 'owner' => ['table' => 'members']],
        'stores.archive' => ['layers' => ['archive']],
        'stores.files'   => ['layers' => ['runtime']],
    ], ownerTables: ['members' => 'bigint']);

    $main = Schema::connection(DB::getDefaultConnection());
    $archive = Schema::connection('archive_db');

    expect($main->hasTable('app_settings_categories'))->toBeTrue()
        ->and($main->hasTable('app_settings'))->toBeTrue()
        ->and($main->hasTable('app_billing_values'))->toBeTrue()
        ->and($main->hasColumn('app_users', 'owner_id'))->toBeTrue()
        ->and($main->hasTable('app_settings_audit'))->toBeTrue()
        ->and($main->hasTable('app_files'))->toBeFalse()
        // A store on a connection of its own gets that connection's categories and audit too.
        ->and($archive->hasTable('app_archive'))->toBeTrue()
        ->and($archive->hasTable('app_settings_categories'))->toBeTrue()
        ->and($archive->hasTable('app_settings_audit'))->toBeTrue()
        ->and($archive->hasTable('app_settings_definitions'))->toBeFalse();

    settings()->store('billing')->set('billing.currency', 'EUR');
    settings()->store('archive')->set('kept.since', 2020);

    expect(settings()->store('billing')->get('billing.currency'))->toBe('EUR')
        ->and(settings()->store('archive')->get('kept.since'))->toBe(2020);

    packageMigration()->down();

    foreach (['app_settings_categories', 'app_settings', 'app_billing_values', 'app_users', 'app_settings_audit'] as $table) {
        expect($main->hasTable($table))->toBeFalse();
    }

    expect($archive->getTables())->toBe([])
        ->and($main->hasTable('members'))->toBeTrue();

    @unlink($other);
});

it('runs on the default store\'s connection', function (): void {
    useDatabase();

    expect(packageMigration()->getConnection())->toBeNull();

    config()->set('laranail.settings.layers.database.connection', DB::getDefaultConnection());
    app()->forgetScopedInstances();

    expect(packageMigration()->getConnection())->toBe(DB::getDefaultConnection());
});

it('creates the definitions table on the default store\'s connection, after categories', function (): void {
    useDatabase(['definitions.sources' => ['code', 'config', 'database']]);

    expect(Schema::hasTable('settings_definitions'))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('settings_definitions'))->pluck('foreign_table')->all())->toBe(['settings_categories']);

    packageMigration()->down();

    expect(Schema::hasTable('settings_definitions'))->toBeFalse()
        ->and(Schema::hasTable('settings_categories'))->toBeFalse();
});

it('reports nothing it cannot look at, rather than a finding', function (): void {
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => sys_get_temp_dir() . '/laranail-missing-' . bin2hex(random_bytes(4)) . '/db.sqlite', 'prefix' => '']);
    useDatabase();
    $inspector = new SchemaInspector(new DatabaseDriver(
        app('db'),
        'unreachable',
        null,
        true,
        static fn (): SchemaReadinessInterface => app(SchemaReadinessInterface::class),
    ));
    $live = new SchemaInspector(app(StoreRegistry::class)->default()->requireDatabase(''));

    expect($inspector->hasTable('settings'))->toBeNull()
        ->and($inspector->columns('settings'))->toBeNull()
        ->and($inspector->idType('settings'))->toBeNull()
        ->and($live->hasTable('settings'))->toBeTrue()
        ->and($live->idType('settings'))->not->toBeNull()
        ->and($live->idType('settings_audit'))->not->toBeNull()
        ->and($live->idType('no_such_table'))->toBeNull();
});
