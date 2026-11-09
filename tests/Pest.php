<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Tests\TestCase;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Audit\InMemoryAuditSink;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;

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
 * overrides, and run the package's migrations from its stubs, as an application would after
 * `install`. On MySQL and Postgres the schema is dropped first, so every test starts empty.
 *
 * @param array<string, mixed> $config extra `laranail.settings.*` values, set before migrating
 * @param list<string> $stores further stores whose values table to create (as make-store would)
 */
function useDatabase(array $config = [], array $stores = []): void
{
    config()->set('laranail.settings.stores.settings.layers', ['database', 'runtime', 'shipped']);

    foreach ($config as $key => $value) {
        config()->set('laranail.settings.' . $key, $value);
    }

    app()->forgetInstance(DriverFactory::class);
    app()->forgetScopedInstances();

    $directory = sys_get_temp_dir() . '/laranail-settings-migrations-' . bin2hex(random_bytes(4));
    mkdir($directory);

    foreach (['create_settings_categories_table', 'create_settings_table', 'create_settings_audit_table'] as $index => $stub) {
        copy(dirname(__DIR__) . "/database/migrations/{$stub}.php.stub", sprintf('%s/2026_01_01_00000%d_%s.php', $directory, $index, $stub));
    }

    foreach ($stores as $index => $store) {
        $source = (string) file_get_contents(__DIR__ . '/../database/migrations/create_settings_table.php.stub');
        file_put_contents(
            sprintf('%s/2026_01_01_0001%02d_create_settings_%s_table.php', $directory, $index, $store),
            str_replace("private string \$store = 'settings';", "private string \$store = '{$store}';", $source),
        );
    }

    Artisan::call('migrate:fresh', ['--path' => $directory, '--realpath' => true]);
    app(ReadinessProbe::class)->flush();
}
