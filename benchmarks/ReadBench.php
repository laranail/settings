<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Benchmarks;

use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PhpBench\Attributes\Iterations;
use Illuminate\Foundation\Application;
use PhpBench\Attributes\BeforeMethods;
use Illuminate\Support\Facades\Artisan;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Orchestra\Testbench\Foundation\Application as Testbench;
use Simtabi\Laranail\DbTools\Providers\DbToolsServiceProvider;
use Simtabi\Laranail\Settings\Providers\SettingsServiceProvider;
use Simtabi\Laranail\Package\Tools\Providers\PackageToolsServiceProvider;

/**
 * The cache's performance gate: one request's reads, with and without the settings cache.
 *
 * Every revolution is one request: the scoped services (registry, resolver, the cache's
 * in-request memory) are rebuilt, then ten settings of one owner are read through the cascade
 * owner → team → global, all in one SQLite-file store. Subjects:
 *
 *  - uncached:   cache.enabled off: one query per read.
 *  - cold:       caching on, every bag invalidated first: the query plus the cache writes.
 *  - warm:       caching on, bags cached in the `array` store (in-process).
 *  - warm file:  caching on, bags cached in the `file` store (serialised to disk, the nearest
 *                local stand-in for a networked store).
 *
 * Run with `composer bench`.
 */
#[Iterations(5)]
#[Revs(200)]
#[Warmup(1)]
final class ReadBench
{
    private const array KEYS = ['ui.theme', 'ui.density', 'ui.language', 'mail.from', 'mail.name', 'site.name', 'site.tagline', 'site.timezone', 'feature.beta', 'feature.limit'];

    private Application $app;

    private BenchOwner $owner;

    public function setUpUncached(): void
    {
        $this->boot(['enabled' => false]);
    }

    public function setUpCached(): void
    {
        $this->boot(['enabled' => true, 'store' => 'array']);
    }

    public function setUpCachedFile(): void
    {
        $this->boot(['enabled' => true, 'store' => 'file']);
    }

    #[BeforeMethods('setUpUncached')]
    public function benchUncached(): void
    {
        $this->request();
    }

    #[BeforeMethods('setUpCached')]
    public function benchCold(): void
    {
        $this->app->forgetScopedInstances();
        $this->app->make(SettingsCache::class)->forgetAll();
        $this->request();
    }

    #[BeforeMethods('setUpCached')]
    public function benchWarm(): void
    {
        $this->request();
    }

    #[BeforeMethods('setUpCachedFile')]
    public function benchWarmFileStore(): void
    {
        $this->request();
    }

    /** One request: fresh scoped services, then every key read through the cascade. */
    private function request(): void
    {
        $this->app->forgetScopedInstances();
        $settings = $this->app->make(Settings::class)->for($this->owner);

        foreach (self::KEYS as $key) {
            $settings->get($key);
        }
    }

    /** @param array<string, mixed> $cache `laranail.settings.cache` */
    private function boot(array $cache): void
    {
        $directory = sys_get_temp_dir() . '/laranail-settings-bench-' . bin2hex(random_bytes(4));
        mkdir($directory);
        touch($directory . '/database.sqlite');

        $this->app = Testbench::create(options: ['extra' => [
            'providers'     => [PackageToolsServiceProvider::class, DbToolsServiceProvider::class, SettingsServiceProvider::class],
            'dont-discover' => ['*'],
        ]]);

        $config = $this->app->make('config');
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('database.default', 'bench');
        $config->set('database.connections.bench', ['driver' => 'sqlite', 'database' => $directory . '/database.sqlite', 'prefix' => '', 'foreign_key_constraints' => true]);
        $config->set('cache.stores.file.path', $directory . '/cache');
        $config->set('laranail.settings.layers.shipped.path', $directory . '/none.php');
        $config->set('laranail.settings.stores.settings.layers', ['database']);
        $config->set('laranail.settings.stores.settings.owners', ['owner', 'team']);
        $config->set('laranail.settings.stores.settings.inherits', true);
        $config->set('laranail.settings.audit.sinks', ['null']);
        $config->set('laranail.settings.cache', $cache + ['prefix' => 'bench', 'ttl' => 3600, 'stale' => 300]);

        Artisan::call('migrate', ['--path' => dirname(__DIR__) . '/database/migrations', '--realpath' => true]);

        $settings = $this->app->make(Settings::class);
        $this->owner = new BenchOwner(1, 7);

        foreach (self::KEYS as $index => $key) {
            match ($index % 3) {
                0       => $settings->set($key, 'global-' . $index),
                1       => $settings->for(new OwnerRef('team', '7'))->set($key, 'team-' . $index),
                default => $settings->for(new OwnerRef('owner', '1'))->set($key, 'owner-' . $index),
            };
        }

        // Fill the cache once, so the warm subjects start warm.
        $this->request();
    }
}
