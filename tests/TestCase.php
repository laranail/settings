<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests;

use Illuminate\Support\Facades\Storage;
use Override;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Simtabi\Laranail\Package\Tools\Providers\PackageToolsServiceProvider;
use Simtabi\Laranail\Package\Tools\Testing\IsolatedTestCase;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;
use Simtabi\Laranail\Settings\SettingsManager;

abstract class TestCase extends IsolatedTestCase
{
    /** @var array<string, mixed> configuration applied while the application boots (rebootWith()) */
    private static array $bootConfig = [];

    /** @var list<class-string> providers registered after the package's, for bootWith() */
    private static array $extraProviders = [];

    /** @var list<class-string> providers registered before the package's, for bootWith() */
    private static array $earlyProviders = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSettings();
    }

    /** Macros are static, so one test's configurator macros must not reach the next test. */
    #[Override]
    protected function tearDown(): void
    {
        SettingsManager::flushMacros();

        parent::tearDown();
    }

    /**
     * Boot the application again with this configuration in place before the providers boot (the
     * REST API registers its routes at boot, only when http.enabled is on).
     *
     * @param  array<string, mixed>  $config  full config keys
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
     * Boot the application again with further service providers, as an application's own would be
     * registered (a configurator's, say). The suite's test configuration still applies, except the
     * table prefix, which stays what the boot left it, so a configurator's prefix can be read.
     *
     * @param  list<class-string>  $after  registered after the package's provider
     * @param  list<class-string>  $before  registered before it
     */
    public function bootWith(array $after, array $before = []): void
    {
        self::$extraProviders = $after;
        self::$earlyProviders = $before;

        try {
            $this->refreshApplication();
        } finally {
            self::$extraProviders = [];
            self::$earlyProviders = [];
        }

        $prefix = config('laranail.settings.tables.prefix');
        $this->configureSettings();
        config()->set('laranail.settings.tables.prefix', $prefix);
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
        $app['config']->set('database.connections.'.$connection, [
            'driver' => $connection,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('DB_DATABASE') ?: 'settings',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $connection === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'schema' => 'public',
        ]);
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders($app): array
    {
        // package-tools' own provider is auto-discovered in an application; it holds the seeder
        // registry the package's seeders are registered into.
        return [...self::$earlyProviders, PackageToolsServiceProvider::class, DbToolsServiceProvider::class, SettingsServiceProvider::class, ...self::$extraProviders];
    }

    private function configureSettings(): void
    {
        // The runtime layer writes through the `local` disk; keep every test's files apart.
        Storage::fake('local');
        // No shipped file unless a test ships one.
        config()->set('laranail.settings.layers.shipped.path', '/nonexistent/laranail-settings-defaults.php');
        // The suite names its tables without the shipped `laranail_` prefix; DefaultTableNamesTest
        // covers the shipped default.
        config()->set('laranail.settings.tables.prefix', '');
        // File layers only unless a test asks for the database (useDatabase() in tests/Pest.php).
        config()->set('laranail.settings.stores.settings.layers', ['runtime', 'shipped']);
    }
}
