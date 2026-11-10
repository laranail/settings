<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests;

use Override;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Package\Tools\Testing\IsolatedTestCase;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;

abstract class TestCase extends IsolatedTestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The runtime layer writes through the `local` disk; keep every test's files apart.
        Storage::fake('local');
        // No shipped file unless a test ships one.
        config()->set('laranail.settings.layers.shipped.path', '/nonexistent/laranail-settings-defaults.php');
        // The schema-shape file goes to a path of this test's own, never the skeleton's database/.
        config()->set('laranail.settings.schema.path', sys_get_temp_dir() . '/laranail-settings-schema-' . bin2hex(random_bytes(6)) . '.php');
        // File layers only unless a test asks for the database (useDatabase() in tests/Pest.php).
        config()->set('laranail.settings.stores.settings.layers', ['runtime', 'shipped']);
    }

    /**
     * SQLite in memory by default. CI runs the `database` group on MySQL and Postgres by
     * exporting DB_CONNECTION (mysql, mariadb or pgsql) and DB_HOST, DB_PORT, DB_DATABASE,
     * DB_USERNAME and DB_PASSWORD; phpunit.xml's DB_CONNECTION does not override an exported one.
     */
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $connection = getenv('DB_CONNECTION');

        if (! in_array($connection, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $app['config']->set('database.default', $connection);
        $app['config']->set('database.connections.' . $connection, [
            'driver'    => $connection,
            'host'      => getenv('DB_HOST') ?: '127.0.0.1',
            'port'      => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
            'database'  => getenv('DB_DATABASE') ?: 'settings',
            'username'  => getenv('DB_USERNAME') ?: 'root',
            'password'  => getenv('DB_PASSWORD') ?: '',
            'charset'   => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $connection === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'schema'    => 'public',
        ]);
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [DbToolsServiceProvider::class, SettingsServiceProvider::class];
    }
}
