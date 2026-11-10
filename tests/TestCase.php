<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests;

use Override;
use Illuminate\Support\Facades\Storage;
use Simtabi\Laranail\Package\Tools\Testing\IsolatedTestCase;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;
use Simtabi\Laranail\Package\Tools\Providers\PackageToolsServiceProvider;

abstract class TestCase extends IsolatedTestCase
{
    /** @var array<string, mixed> configuration applied while the application boots (rebootWith()) */
    private static array $bootConfig = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSettings();
    }

    /**
     * Boot the application again with this configuration in place before the providers boot (the
     * REST API registers its routes at boot, only when http.enabled is on).
     *
     * @param array<string, mixed> $config full config keys
     */
    public function rebootWith(array $config): void
    {
        self::$bootConfig = $config;

        try {
            $this->refreshApplication();
        } finally {
            self::$bootConfig = [];
        }

        $this->configureSettings();
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

        foreach (self::$bootConfig as $key => $value) {
            $app['config']->set($key, $value);
        }

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
        // package-tools' own provider is auto-discovered in an application; it holds the seeder
        // registry the package's seeders are registered into.
        return [PackageToolsServiceProvider::class, DbToolsServiceProvider::class, SettingsServiceProvider::class];
    }

    private function configureSettings(): void
    {
        // The runtime layer writes through the `local` disk; keep every test's files apart.
        Storage::fake('local');
        // No shipped file unless a test ships one.
        config()->set('laranail.settings.layers.shipped.path', '/nonexistent/laranail-settings-defaults.php');
        // The schema-shape file goes to a path of this test's own, never the skeleton's database/.
        config()->set('laranail.settings.schema.path', sys_get_temp_dir() . '/laranail-settings-schema-' . bin2hex(random_bytes(6)) . '.php');
        // File layers only unless a test asks for the database (useDatabase() in tests/Pest.php).
        config()->set('laranail.settings.stores.settings.layers', ['runtime', 'shipped']);
    }
}
