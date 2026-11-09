<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Providers;

use Override;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Package\Tools\Package;
use Illuminate\Contracts\Validation\Factory;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\SettingsManager;
use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Support\ValuePipeline;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Simtabi\Laranail\Settings\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Drivers\ReadOnlyValues;
use Simtabi\Laranail\Settings\Contracts\ValueEncrypter;
use Simtabi\Laranail\Settings\Contracts\ValueValidator;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Support\LaravelValueValidator;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;

/**
 * Registers laranail/settings with the host application.
 *
 * Every name it registers is vendor-scoped: config at `laranail.settings`, publish tags
 * `laranail::settings-*`, and the install command `laranail::settings.install`. Nothing here
 * touches the database or the filesystem at boot: stores, drivers and definitions are built on
 * first use, and the encrypter is resolved only when a value is encrypted or decrypted.
 */
final class SettingsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/settings')
            ->setPublishTagId('settings')
            ->hasConfigFile('settings')
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command->publishConfigFile();
            });
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->app->singleton(CasterRegistry::class);
        $this->app->singleton(ValueEncoder::class);
        $this->app->singleton(RecordSerializer::class);

        $this->app->singleton(ValueValidator::class, static fn (Application $app): ValueValidator => new LaravelValueValidator($app->make(Factory::class)));

        $this->app->singleton(ValueEncrypter::class, static fn (Application $app): ValueEncrypter => new LaravelValueEncrypter(
            static fn (): StringEncrypter => $app->make(StringEncrypter::class),
        ));

        $this->app->singleton(DefinitionRegistry::class, static fn (Application $app): DefinitionRegistry => new DefinitionRegistry(
            new StoreName(self::string($app, 'laranail.settings.default', 'settings')),
            $app->make(CasterRegistry::class),
            self::array($app, 'laranail.settings.definitions'),
        ));

        $this->app->singleton(DriverFactory::class, static fn (Application $app): DriverFactory => new DriverFactory(
            $app->make(Disks::class),
            $app->make(Repository::class),
            new ReadOnlyValues($app->make(ValueEncoder::class)),
            $app->make(RecordSerializer::class),
            $app->basePath(),
        ));

        // Scoped: rebuilt per request and per queued job under Octane and Horizon, so no
        // per-request state can survive into the next one.
        $this->app->scoped(StoreRegistry::class, static fn (Application $app): StoreRegistry => new StoreRegistry(
            $app->make(DriverFactory::class),
            self::string($app, 'laranail.settings.default', 'settings'),
            self::array($app, 'laranail.settings.stores'),
            self::array($app, 'laranail.settings.layers'),
        ));

        $this->app->singleton(ValuePipeline::class);

        $this->app->scoped(Resolver::class, static fn (Application $app): Resolver => new Resolver(
            $app->make(StoreRegistry::class),
            $app->make(ValuePipeline::class),
            self::string($app, 'app.fallback_locale', 'en'),
        ));

        $this->app->singleton(SettingWriter::class, static fn (Application $app): SettingWriter => new SettingWriter(
            $app->make(ValuePipeline::class),
            $app->make(Dispatcher::class),
            static fn (): CarbonImmutable => CarbonImmutable::now(),
        ));

        $this->app->scoped(Settings::class, SettingsManager::class);
    }

    private static function string(Application $app, string $key, string $default): string
    {
        $value = $app->make(Repository::class)->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /** @return array<array-key, mixed> */
    private static function array(Application $app, string $key): array
    {
        $value = $app->make(Repository::class)->get($key, []);

        return is_array($value) ? $value : [];
    }
}
