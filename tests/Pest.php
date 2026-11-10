<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Tests\TestCase;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;

// Feature tests boot the application; Unit and Arch tests deliberately do not, so a unit
// that quietly relies on the container fails instead of passing.
uses(TestCase::class)->in('Feature');
// Everything that touches a real database; CI runs this group again on MySQL and Postgres.
// On MySQL and Postgres the database outlives a test, so each starts from an empty schema.
uses()->group('database')->beforeEach(function (): void {
    Schema::dropAllTables();
})->in('Feature/Database');

function settings(): Settings
{
    return app(Settings::class);
}

/**
 * Write a shipped-defaults PHP file and point the `shipped` layer at it.
 *
 * @param array<string, mixed> $values
 */
function shipDefaults(array $values): void
{
    $path = sys_get_temp_dir() . '/laranail-settings-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
    config()->set('laranail.settings.layers.shipped.path', $path);
}

/** An unsaved user the access tests act as. */
function user(int $id = 1): TestUser
{
    return TestUser::make($id);
}

/** Define the `laranail-settings.<ability>` gate for each ability, allowing every user. */
function allowGates(Ability ...$abilities): void
{
    foreach ($abilities as $ability) {
        Gate::define($ability->gateName(), static fn (TestUser $user): bool => true);
    }
}

/** Record audit entries in memory for this request. */
function auditInMemory(): InMemoryAuditSink
{
    $sink = new InMemoryAuditSink;
    settings()->configuration()->auditTo($sink);

    return $sink;
}

/**
 * Put the default store on the database (database, runtime, shipped), with any store
 * overrides, and run the package's migration, as an application would after
 * `install`. On MySQL and Postgres the schema is dropped first, so every test starts empty.
 *
 * @param array<string, mixed> $config extra `laranail.settings.*` values, set before migrating
 * @param list<string> $stores further stores whose values table to create (as make-store would)
 * @param array<string, string> $ownerTables tables to create first (name => bigint|uuid|ulid key), for dedicated stores
 */
function useDatabase(array $config = [], array $stores = [], array $ownerTables = []): void
{
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);

    foreach ($config as $key => $value) {
        config()->set('laranail.settings.' . $key, $value);
    }

    app()->forgetInstance(DriverFactory::class);
    app()->forgetScopedInstances();

    // One directory per distinct set of files, reused: the migrator requires each file, and PHP
    // keeps every compiled file for the life of the process, so a fresh path per call would leak
    // a migration's worth of memory per test (the full switch matrix runs thousands of them).
    $migration = (string) file_get_contents(__DIR__ . '/../database/migrations/create_laranail_settings_tables.php');
    $directory = sys_get_temp_dir() . '/laranail-settings-migrations-' . sha1(serialize([$migration, $stores, $ownerTables]));

    if (is_dir($directory)) {
        Artisan::call('migrate:fresh', ['--path' => $directory, '--realpath' => true]);
        app(ReadinessProbe::class)->flush();

        return;
    }

    mkdir($directory);

    copy(__DIR__ . '/../database/migrations/create_laranail_settings_tables.php', $directory . '/2026_01_01_000000_create_laranail_settings_tables.php');

    if ($ownerTables !== []) {
        file_put_contents($directory . '/2025_01_01_000000_create_owner_tables.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {
            public function up(): void
            {
                foreach (' . var_export($ownerTables, true) . ' as $name => $type) {
                    Illuminate\Support\Facades\Schema::create($name, static function (Illuminate\Database\Schema\Blueprint $table) use ($type): void {
                        match ($type) {
                            "uuid"  => $table->char("id", 36)->primary(),
                            "ulid"  => $table->char("id", 26)->primary(),
                            default => $table->bigIncrements("id"),
                        };
                        $table->string("name")->nullable();
                    });
                }
            }
        };');
    }

    foreach ($stores as $index => $store) {
        $source = (string) file_get_contents(__DIR__ . '/../database/migrations/create_laranail_settings_tables.php');
        file_put_contents(
            sprintf('%s/2026_01_01_0001%02d_create_settings_%s_table.php', $directory, $index, $store),
            str_replace('private ?string $only = null;', "private ?string \$only = '{$store}';", $source),
        );
    }

    Artisan::call('migrate:fresh', ['--path' => $directory, '--realpath' => true]);
    app(ReadinessProbe::class)->flush();
}
