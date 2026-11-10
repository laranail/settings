<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Providers;

use Override;
use Throwable;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Log\LogManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Cache\RateLimiting\Limit;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Data\AlertRule;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\SettingsManager;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Simtabi\Laranail\Settings\Actions\MakeStore;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Illuminate\Contracts\Cache\Factory as Caches;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Simtabi\Laranail\Settings\Actions\ClearCache;
use Simtabi\Laranail\Settings\Audit\LogAuditSink;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Audit\AutoAuditSink;
use Simtabi\Laranail\Settings\Audit\NullAuditSink;
use Simtabi\Laranail\Settings\Console\DiffCommand;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Data\ApprovalPolicy;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Typed\TypedSettings;
use Illuminate\Cache\Repository as CacheRepository;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Console\DoctorCommand;
use Simtabi\Laranail\Settings\Console\ExportCommand;
use Simtabi\Laranail\Settings\Console\ImportCommand;
use Simtabi\Laranail\Settings\Http\Support\Problems;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Simtabi\Laranail\Settings\Console\HistoryCommand;
use Simtabi\Laranail\Settings\Console\PromoteCommand;
use Simtabi\Laranail\Settings\Console\RescopeCommand;
use Simtabi\Laranail\Settings\Drivers\ReadOnlyValues;
use Simtabi\Laranail\Settings\Typed\SettingsMigrator;
use Simtabi\Laranail\Settings\Actions\MigrateSettings;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Audit\DatabaseAuditSink;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Services\Access\Auditor;
use Simtabi\Laranail\Settings\Typed\SettingsMigration;
use Simtabi\Laranail\Settings\Console\MakeStoreCommand;
use Simtabi\Laranail\Settings\Console\ReencryptCommand;
use Simtabi\Laranail\Settings\Console\WarmCacheCommand;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Actions\FindForeignTables;
use Simtabi\Laranail\Settings\Console\ClearCacheCommand;
use Simtabi\Laranail\Settings\Console\PruneAuditCommand;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Verification\NullVerifier;
use Simtabi\Laranail\Settings\Listeners\LogSettingsEvent;
use Simtabi\Laranail\Settings\Console\PruneChangesCommand;
use Simtabi\Laranail\Settings\Console\PruneHistoryCommand;
use Simtabi\Laranail\Settings\Console\PruneOrphansCommand;
use Simtabi\Laranail\Settings\Listeners\SendSettingsAlert;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand;
use Simtabi\Laranail\Settings\Authorization\GateAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Authorization\ChainAuthorizer;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Console\SyncDefinitionsCommand;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Http\Middleware\IdempotentWrites;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Simtabi\Laranail\Settings\Authentication\GuardActorResolver;
use Simtabi\Laranail\Settings\Authentication\SanctumActorResolver;
use Simtabi\Laranail\Settings\Authorization\PermissionsAuthorizer;
use Simtabi\Laranail\Settings\Verification\ActorTimestampVerifier;
use Illuminate\Contracts\Notifications\Dispatcher as Notifications;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\Settings\Services\Tenancy\TenantDiscriminators;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\Settings\Database\Seeders\DefaultCategoriesSeeder;
use Simtabi\Laranail\Settings\Services\Definitions\DatabaseDefinitions;
use Simtabi\Laranail\Settings\Verification\PasswordConfirmationVerifier;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Package\Tools\Support\Definitions\AutoSeederDefinition;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/**
 * Registers laranail/settings with the host application.
 *
 * Every name it registers is vendor-scoped: config at `laranail.settings`, publish tags
 * `laranail::settings-*`, and the install command `laranail::settings.install`. Nothing here
 * touches the database or the filesystem at boot: stores, drivers and definitions are built on
 * first use, and the encrypter is resolved only when a value is encrypted or decrypted.
 *
 * Most classes are autowired: their lifetime is a `#[Scoped]` or `#[Singleton]` attribute on the
 * class, and their configuration a `#[Config]` attribute on the parameter. What is bound here is
 * what configuration chooses or validates (drivers, sinks, authorizers, verifiers, resolvers,
 * cache stores), and the few services whose collaborators are closures on purpose. Everything
 * derived from a request is scoped, so Octane and queue workers start each request and job clean.
 * Configuration naming an unknown driver, sink or level raises InvalidConfiguration with its
 * config path on first use.
 */
final class SettingsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/settings')
            ->setPublishTagId('settings')
            ->hasConfigFile('settings')
            // `laranail/settings::settings.*` (resources/lang) and the one view, the alert mail
            // (`laranail/settings::mail.alert`); both publishable. The REST API's routes are
            // loaded in packageBooted(), behind the http.enabled switch.
            ->hasTranslations()
            ->hasViews()
            ->hasMigrations(['create_laranail_settings_tables'])
            // Runs with the host's `php artisan db:seed`; the seeder itself honours seed.enabled.
            // Foreign keys stay checked (a child category needs its parent) and events fire (the
            // settings API dispatches CategorySaving/Saved and writes the audit trail).
            ->hasPackageSeeders(AutoSeederDefinition::make('laranail/settings:defaults')
                ->seeders([DefaultCategoriesSeeder::class])
                ->options(['disable_foreign_key_checks' => false, 'fire_events' => true]))
            // The REST API's limit: `http.rate_limit` requests a minute per user (or IP); 0 is none.
            ->registerRateLimiter('laranail-settings', static function (Request $request): Limit {
                $perMinute = config()->integer('laranail.settings.http.rate_limit', 60);
                $user = $request->user()?->getAuthIdentifier();

                return $perMinute > 0
                    ? Limit::perMinute($perMinute)->by(is_scalar($user) ? 'user:' . $user : 'ip:' . $request->ip())
                    : Limit::none();
            })
            ->hasCommands([
                PromoteCommand::class,
                PruneOrphansCommand::class,
                PruneAuditCommand::class,
                PruneHistoryCommand::class,
                PruneChangesCommand::class,
                HistoryCommand::class,
                MakeStoreCommand::class,
                DoctorCommand::class,
                SyncDefinitionsCommand::class,
                WarmCacheCommand::class,
                ClearCacheCommand::class,
                ExportCommand::class,
                ImportCommand::class,
                DiffCommand::class,
                ReencryptCommand::class,
                RescopeCommand::class,
            ])
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->startWith(static function (InstallCommand $command): void {
                        self::refuseForeignTables($command);
                        self::recordSchema($command);
                    })
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->endWith(static function (InstallCommand $command): void {
                        self::syncDefinitions($command);
                    });
            });
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->app->scoped(Settings::class, SettingsManager::class);

        $this->registerTenancy();
        $this->registerStores();
        $this->registerDefinitions();
        $this->registerAccess();
        $this->registerHttp();
        $this->registerTyped();
    }

    #[Override]
    public function packageBooted(): void
    {
        $app = $this->app;
        $events = $app->make(Dispatcher::class);
        $config = $app->make(Repository::class);

        // db-tools memoises readiness in a singleton: forget it once the schema may have changed,
        // at the start of every queued job and Octane request, and on the tenant switches the
        // tenancy packages announce (`readiness.flush_on`; db-tools has no hook of its own).
        $flush = static function () use ($app): void {
            $app->make(SchemaReadinessInterface::class)->flush();
        };

        foreach ([MigrationsEnded::class, JobProcessing::class, 'Laravel\\Octane\\Events\\RequestReceived', ...self::strings($config, 'laranail.settings.readiness.flush_on')] as $event) {
            $events->listen($event, $flush);
        }

        // Each typed settings class `typed.classes` lists resolves loaded, through the request's handle.
        foreach (self::strings($config, 'laranail.settings.typed.classes') as $class) {
            if (is_subclass_of($class, TypedSettings::class)) {
                $app->bind($class, static fn (Application $app): TypedSettings => $class::load($app->make(Settings::class)));
            }
        }

        if (filter_var($config->get('laranail.settings.http.enabled', false), FILTER_VALIDATE_BOOL)) {
            $this->bootHttp($config);
        }

        // The built-in listeners, registered by class: nothing is built until an event fires.
        if ($config->get('laranail.settings.listeners.log', false) === true) {
            $events->listen(LogSettingsEvent::EVENTS, LogSettingsEvent::class);
        }

        if ($config->get('laranail.settings.listeners.alerts', true) !== false && filter_var($config->get('laranail.settings.alerts.enabled', false), FILTER_VALIDATE_BOOL)) {
            $events->listen(SendSettingsAlert::EVENTS, SendSettingsAlert::class);
        }
    }

    /** `cache.enabled`: on unless set to a false value. */
    private static function cacheEnabled(Application $app): bool
    {
        return filter_var($app->make(Repository::class)->get('laranail.settings.cache.enabled', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** A Laravel cache repository by store name (null: the default store), or InvalidConfiguration naming `$path`. */
    private static function cacheStore(Application $app, mixed $name, string $path): CacheRepository
    {
        if ($name !== null && (! is_string($name) || $name === '')) {
            throw new InvalidConfiguration($path . ': a cache store name, or null for the default store.');
        }

        try {
            $cache = $app->make(Caches::class)->store($name);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfiguration(sprintf('%s: %s', $path, $exception->getMessage()), $exception->getCode(), previous: $exception);
        }

        return $cache instanceof CacheRepository ? $cache : throw new InvalidConfiguration(sprintf('%s: [%s] is not a Laravel cache repository.', $path, get_debug_type($cache)));
    }

    /**
     * `configuration.sources`: the merge order of `config`, `database` and `runtime` stores and
     * layers, later wins.
     *
     * @return list<string>
     */
    private static function configurationSources(Repository $config): array
    {
        $sources = $config->get('laranail.settings.configuration.sources', ['config', 'database', 'runtime']);
        $names = is_array($sources) ? array_values(array_filter($sources, is_string(...))) : null;

        if ($names === null || count($names) !== count($sources) || array_diff($names, ['config', 'database', 'runtime']) !== [] || count(array_unique($names)) !== count($names)) {
            throw new InvalidConfiguration('laranail.settings.configuration.sources: a list of config, database and runtime, each at most once.');
        }

        return $names;
    }

    /**
     * `definitions.sources` (D-6): which of `code`, `config` and `database` are read. Code
     * definitions are registered in code and are always read.
     *
     * @return list<string>
     */
    private static function definitionSources(Repository $config): array
    {
        $sources = $config->get('laranail.settings.definitions.sources', ['code', 'config', 'database']);
        $names = is_array($sources) ? array_values(array_filter($sources, is_string(...))) : null;

        if ($names === null || count($names) !== count($sources) || array_diff($names, ['code', 'config', 'database']) !== []) {
            throw new InvalidConfiguration('laranail.settings.definitions.sources: a list of code, config and database.');
        }

        return $names;
    }

    /** @return list<string> the strings of the array at `$key` (other entries dropped) */
    private static function strings(Repository $config, string $key): array
    {
        return array_values(array_filter($config->array($key, []), is_string(...)));
    }

    private static function authorizer(Application $app, mixed $driver, string $path): Authorizer
    {
        return match ($driver) {
            'gate'        => new GateAuthorizer($app->make(Gate::class)),
            'permissions' => new PermissionsAuthorizer($app->make(Repository::class)->array('laranail.settings.authorization.permissions', [])),
            default       => self::instance($app, $driver, Authorizer::class, $path),
        };
    }

    private static function auditSink(Application $app, mixed $sink, string $path): AuditSink
    {
        // The database sink finds its store per entry, so it reaches the registry through a
        // closure: the registry's own collaborators audit through this sink.
        $database = static fn (): DatabaseAuditSink => new DatabaseAuditSink(static fn (): StoreRegistry => $app->make(StoreRegistry::class));

        return match ($sink) {
            'auto'     => new AutoAuditSink($database(), new LogAuditSink($app->make(OperationalLogger::class))),
            'database' => $database(),
            'log'      => new LogAuditSink($app->make(OperationalLogger::class)),
            'null'     => new NullAuditSink,
            default    => self::instance($app, $sink, AuditSink::class, $path),
        };
    }

    /**
     * A custom implementation named by class in config: it must exist and implement the contract.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return T
     */
    private static function instance(Application $app, mixed $class, string $contract, string $path): object
    {
        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, $contract)) {
            throw new InvalidConfiguration(sprintf('%s: [%s] is not a built-in name or a class implementing %s.', $path, is_scalar($class) ? $class : get_debug_type($class), $contract));
        }

        $instance = $app->make($class);

        return $instance instanceof $contract ? $instance : throw new InvalidConfiguration(sprintf('%s: [%s] did not build a %s.', $path, $class, $contract));
    }

    /**
     * Install refuses to publish migrations over a table of the same name that is not this
     * package's (D-4): rename the package's tables with `tables.prefix` or `stores.<name>.table`.
     */
    private static function refuseForeignTables(InstallCommand $command): void
    {
        $foreign = $command->getLaravel()->make(FindForeignTables::class)->handle();

        if ($foreign !== []) {
            throw new InvalidConfiguration(sprintf(
                'These tables already exist and are not laranail/settings tables: %s. Set laranail.settings.tables.prefix (or stores.<name>.table, tables.categories, tables.audit) and install again.',
                implode(', ', $foreign),
            ));
        }
    }

    /**
     * Record the schema the published migrations will produce (§4.0), so the runtime reads that
     * record rather than live configuration. An existing record is kept and only new stores are
     * added to it.
     */
    private static function recordSchema(InstallCommand $command): void
    {
        $shapes = $command->getLaravel()->make(SchemaShapeRepository::class);
        $shapes->record();
        $command->info('Recorded the settings schema in ' . $shapes->path());
    }

    /** Copy code and config definitions into the definitions table, when it exists already. */
    private static function syncDefinitions(InstallCommand $command): void
    {
        try {
            $report = $command->getLaravel()->make(SyncDefinitions::class)->handle(Actor::system('laranail::settings.install'));
        } catch (SettingsException) {
            $command->comment('Definitions were not synced (run the migrations, then laranail::settings.sync-definitions).');

            return;
        }

        if ($report['synced'] > 0) {
            $command->info(sprintf('Synced %d definition(s) into the definitions table.', $report['synced']));
        }
    }

    /**
     * The REST API (§16.10): routes/api.php under `http.prefix`/v1 (an empty prefix mounts it at
     * /v1), and problem details for the errors its routes raise. Reads configuration only.
     */
    private function bootHttp(Repository $config): void
    {
        $middleware = $config->get('laranail.settings.http.middleware');
        $middleware = is_array($middleware)
            ? array_values(array_filter($middleware, is_string(...)))
            : ['api', class_exists('Laravel\\Sanctum\\Sanctum') ? 'auth:sanctum' : 'auth'];
        $prefix = trim($config->string('laranail.settings.http.prefix', 'api/settings'), '/');

        if (! $this->app instanceof CachesRoutes || ! $this->app->routesAreCached()) {
            Route::prefix(ltrim($prefix . '/v1', '/'))
                ->name('laranail-settings.api.')
                ->middleware([...$middleware, 'throttle:laranail-settings'])
                ->where(['store' => '[a-z][a-z0-9_]*', 'key' => '[A-Za-z0-9_.]+', 'alias' => '[a-z][a-z0-9_]*', 'kind' => 'stores|layers'])
                ->group($this->package->basePath('/routes/api.php'));
        }

        $handler = $this->app->make(ExceptionHandler::class);
        $app = $this->app;

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(static fn (Throwable $exception, Request $request): mixed => $app->make(Problems::class)->render($exception, $request));
        }
    }

    /**
     * Tenancy (Amendment B): who the current tenant is, and the context the Eloquent models read
     * it from. `tenancy.discriminator`: `auto`, `null`, `stancl`, `spatie`, or a class
     * implementing TenantDiscriminator.
     */
    private function registerTenancy(): void
    {
        $this->app->scoped(TenantDiscriminator::class, static function (Application $app): TenantDiscriminator {
            $config = $app->make(Repository::class);
            $binding = $config->get('multitenancy.current_tenant_container_key', 'currentTenant');

            return new TenantDiscriminators($app, is_string($binding) ? $binding : 'currentTenant')
                ->make($config->get('laranail.settings.tenancy.discriminator', 'auto'));
        });

        $app = $this->app;
        ModelContext::use(
            static fn (): SchemaShape => $app->make(SchemaShapeRepository::class)->current(),
            static fn (): ?string => $app->make(TenantDiscriminator::class)->current(),
            static fn (): string => $app->make(Repository::class)->string('laranail.settings.default', 'settings'),
        );
    }

    /** Drivers, the recorded schema, the stores, the runtime configuration and the cache. */
    private function registerStores(): void
    {
        // A singleton, so a driver registered with extend() lasts; the scoped services it hands
        // to database drivers are closures, resolved for the request that uses them.
        $this->app->singleton(DriverFactory::class, static fn (Application $app): DriverFactory => new DriverFactory(
            $app->make(Disks::class),
            $app->make(Repository::class),
            new ReadOnlyValues($app->make(ValueEncoder::class)),
            $app->make(RecordSerializer::class),
            $app->basePath(),
            $app->make(ConnectionResolverInterface::class),
            SchemaNames::fromConfig($app->make(Repository::class)->array('laranail.settings.tables', [])),
            static fn (): SchemaReadinessInterface => $app->make(SchemaReadinessInterface::class),
            static fn (): SchemaShape => $app->make(SchemaShapeRepository::class)->current(),
            static fn (): ?string => $app->make(TenantDiscriminator::class)->current(),
        ));

        $this->app->scoped(SchemaShapeRepository::class, static function (Application $app): SchemaShapeRepository {
            $path = $app->make(Repository::class)->get('laranail.settings.schema.path');

            return new SchemaShapeRepository($app->make(Filesystem::class), is_string($path) && $path !== '' ? $path : $app->databasePath('settings-schema.php'), $app->make(Repository::class));
        });

        // The operator's flag, or laranail/installer-headless (suggested) reporting an install.
        $this->app->scoped(InstallationEvidence::class, static function (Application $app): InstallationEvidence {
            $installer = 'Simtabi\\Laranail\\Installer\\Headless\\Support\\InstallationState';

            return new InstallationEvidence(
                $app->make(Repository::class)->get('laranail.settings.bootstrap', false) === true,
                class_exists($installer) ? static function () use ($app, $installer): bool {
                    $state = $app->make($installer);

                    return is_object($state) && method_exists($state, 'isInstalling') && $state->isInstalling() === true;
                } : null,
            );
        });

        // Its change log is a closure: the logger and the auditor read the runtime configuration.
        $this->app->scoped(RuntimeConfiguration::class, static fn (Application $app): RuntimeConfiguration => new RuntimeConfiguration(
            $app->make(DriverFactory::class),
            $app->make(Repository::class)->string('laranail.settings.default', 'settings'),
            $app->make(Repository::class)->array('laranail.settings.stores', []),
            $app->make(Repository::class)->array('laranail.settings.layers', []),
            $app->make(CasterRegistry::class),
            static function (string $change) use ($app): void {
                $app->make(OperationalLogger::class)->info('laranail/settings runtime configuration: ' . $change);
                $app->make(Auditor::class)->record(AuditAction::Configure, AuditOutcome::Allowed, null, null, meta: ['change' => $change]);
            },
        ));

        $this->app->scoped(StoreRegistry::class, static fn (Application $app): StoreRegistry => new StoreRegistry(
            $app->make(DriverFactory::class),
            $app->make(Repository::class)->string('laranail.settings.default', 'settings'),
            $app->make(Repository::class)->array('laranail.settings.stores', []),
            $app->make(Repository::class)->array('laranail.settings.layers', []),
            runtime: $app->make(RuntimeConfiguration::class),
            evidence: $app->make(InstallationEvidence::class),
            events: $app->make(Dispatcher::class),
            cache: self::cacheEnabled($app) ? $app->make(SettingsCache::class) : null,
            persisted: $app->make(PersistedConfiguration::class),
            sources: self::configurationSources($app->make(Repository::class)),
        ));

        // The settings cache (§7.5): the configured cache store, null for the default one.
        $this->app->scoped(SettingsCache::class, static function (Application $app): SettingsCache {
            $config = $app->make(Repository::class);

            return new SettingsCache(
                self::cacheStore($app, $config->get('laranail.settings.cache.store'), 'laranail.settings.cache.store'),
                $config->string('laranail.settings.cache.prefix', 'laranail-settings'),
                $config->integer('laranail.settings.cache.ttl', 3600),
                $config->integer('laranail.settings.cache.stale', 300),
                $app->make(TenantDiscriminator::class),
                $app->make(OperationalLogger::class),
            );
        });

        $this->app->bind(ClearCache::class, static fn (Application $app): ClearCache => new ClearCache(
            $app->make(AccessGuard::class),
            self::cacheEnabled($app) ? $app->make(SettingsCache::class) : null,
            new StoreName($app->make(Repository::class)->string('laranail.settings.default', 'settings')),
        ));

        $this->app->bind(MakeStore::class, static fn (Application $app): MakeStore => new MakeStore(
            $app->make(Filesystem::class),
            dirname(__DIR__, 2) . '/database/migrations/' . MakeStore::MIGRATION . '.php',
            $app->databasePath('migrations'),
            $app->make(SchemaShapeRepository::class),
        ));

        // Resolved only when a value is encrypted or decrypted, so an application without an
        // APP_KEY yet still boots and reads its unencrypted settings.
        $this->app->singleton(LaravelValueEncrypter::class, static fn (Application $app): LaravelValueEncrypter => new LaravelValueEncrypter(
            static fn (): StringEncrypter => $app->make(StringEncrypter::class),
        ));
    }

    /** Code and config definitions, the definitions table (D-6) and the approval policy (§16.7). */
    private function registerDefinitions(): void
    {
        $this->app->singleton(DefinitionRegistry::class, static fn (Application $app): DefinitionRegistry => new DefinitionRegistry(
            new StoreName($app->make(Repository::class)->string('laranail.settings.default', 'settings')),
            $app->make(CasterRegistry::class),
            in_array('config', self::definitionSources($app->make(Repository::class)), true) ? $app->make(Repository::class)->array('laranail.settings.definitions', []) : [],
        ));

        $this->app->scoped(DatabaseDefinitions::class, static fn (Application $app): DatabaseDefinitions => new DatabaseDefinitions(
            $app->make(CasterRegistry::class),
            $app->make(ValueEncoder::class),
            self::strings($app->make(Repository::class), 'laranail.settings.definitions.allowed_rules'),
            in_array('database', self::definitionSources($app->make(Repository::class)), true),
        ));

        $this->app->scoped(ApprovalPolicy::class, static fn (Application $app): ApprovalPolicy => ApprovalPolicy::fromConfig($app->make(Repository::class)->array('laranail.settings.approvals', [])));
    }

    /** Actor resolution, authorization, verification, audit and the operational log. */
    private function registerAccess(): void
    {
        $this->app->scoped(OperationalLogger::class, static function (Application $app): OperationalLogger {
            $config = $app->make(Repository::class);
            $channel = $config->get('laranail.settings.logging.channel');

            return new OperationalLogger(
                $channel === false ? new NullLogger : $app->make(LogManager::class)->channel(is_string($channel) ? $channel : null),
                $config->string('laranail.settings.logging.level', LogLevel::INFO),
                $app->make(RuntimeConfiguration::class),
            );
        });

        $this->app->scoped(Auditor::class, static function (Application $app): Auditor {
            $sinks = $app->make(Repository::class)->array('laranail.settings.audit.sinks', []);

            return new Auditor(
                array_values(array_map(static fn (mixed $sink, int|string $index): AuditSink => self::auditSink($app, $sink, 'laranail.settings.audit.sinks.' . $index), $sinks, array_keys($sinks))),
                $app->make(Clock::class),
                $app->make(RuntimeConfiguration::class),
            );
        });

        $this->app->scoped(Authorizer::class, static function (Application $app): Authorizer {
            $driver = $app->make(Repository::class)->get('laranail.settings.authorization.driver', 'gate');

            return is_array($driver)
                ? new ChainAuthorizer(array_values(array_map(static fn (mixed $name, int|string $index): Authorizer => self::authorizer($app, $name, 'laranail.settings.authorization.driver.' . $index), $driver, array_keys($driver))))
                : self::authorizer($app, $driver, 'laranail.settings.authorization.driver');
        });

        $this->app->scoped(Verifier::class, static fn (Application $app): Verifier => match ($driver = $app->make(Repository::class)->get('laranail.settings.verification.driver', 'password_confirmation')) {
            // The session is read per call: the request changes under this scoped verifier.
            'password_confirmation' => new PasswordConfirmationVerifier(
                static fn () => $app->bound('request') && $app->make('request') instanceof Request && $app->make('request')->hasSession() ? $app->make('request')->session() : null,
                $app->make(Repository::class)->integer('auth.password_timeout', 10800),
                $app->make(Clock::class),
            ),
            'actor' => $app->make(ActorTimestampVerifier::class),
            'none'  => new NullVerifier,
            default => self::instance($app, $driver, Verifier::class, 'laranail.settings.verification.driver'),
        });

        $this->app->scoped(ActorResolver::class, static function (Application $app): ActorResolver {
            $guards = self::strings($app->make(Repository::class), 'laranail.settings.authentication.guards');
            $request = static fn (): ?Request => $app->bound('request') && $app->make('request') instanceof Request ? $app->make('request') : null;

            return match ($resolver = $app->make(Repository::class)->get('laranail.settings.authentication.resolver', 'guard')) {
                'guard'   => new GuardActorResolver($app->make(AuthFactory::class), $guards, $request),
                'sanctum' => new SanctumActorResolver($app->make(AuthFactory::class), $guards, $request),
                default   => self::instance($app, $resolver, ActorResolver::class, 'laranail.settings.authentication.resolver'),
            };
        });

        $this->app->scoped(AccessGuard::class, static function (Application $app): AccessGuard {
            $config = $app->make(Repository::class);
            $undefined = $config->get('laranail.settings.authorization.undefined', 'deny');

            if (! in_array($undefined, ['allow', 'deny'], true)) {
                throw new InvalidConfiguration('laranail.settings.authorization.undefined: use [allow] or [deny].');
            }

            return new AccessGuard(
                $app->make(Authorizer::class),
                $app->make(Verifier::class),
                $app->make(ActorResolver::class),
                $app->make(Auditor::class),
                $app->make(OperationalLogger::class),
                array_map(
                    static fn (string $ability): Ability => Ability::tryFrom($ability) ?? throw new InvalidConfiguration(sprintf('laranail.settings.verification.abilities: unknown ability [%s].', $ability)),
                    self::strings($config, 'laranail.settings.verification.abilities'),
                ),
                $undefined === 'allow',
                $config->get('laranail.settings.authorization.require_actor', false) === true,
                $app->make(RuntimeConfiguration::class),
                $app->make(Dispatcher::class),
            );
        });
    }

    /** The REST API's idempotency cache, and the alert listener with its rules and throttle. */
    private function registerHttp(): void
    {
        $this->app->bind(IdempotentWrites::class, static fn (Application $app): IdempotentWrites => new IdempotentWrites(
            self::cacheStore($app, $app->make(Repository::class)->get('laranail.settings.http.idempotency.cache'), 'laranail.settings.http.idempotency.cache'),
            $app->make(Repository::class)->integer('laranail.settings.http.idempotency.ttl', 86400),
            $app->make(Repository::class)->string('laranail.settings.cache.prefix', 'laranail-settings'),
        ));

        $this->app->bind(SendSettingsAlert::class, static function (Application $app): SendSettingsAlert {
            $config = $app->make(Repository::class);
            $throttle = $config->integer('laranail.settings.alerts.throttle', 300);
            $rules = $config->array('laranail.settings.alerts.rules', []);
            $cache = $config->get('laranail.settings.alerts.cache');
            $store = $throttle > 0 ? $app->make(Caches::class)->store(is_string($cache) && $cache !== '' ? $cache : null) : null;

            return new SendSettingsAlert(
                $app->make(Notifications::class),
                array_values(array_map(
                    static fn (mixed $rule, int|string $index): AlertRule => is_array($rule) ? AlertRule::fromConfig($rule, $index) : throw new InvalidConfiguration(sprintf('laranail.settings.alerts.rules.%s: each rule is an array.', $index)),
                    $rules,
                    array_keys($rules),
                )),
                $app->make(Definitions::class),
                $app->make(Clock::class),
                $app->make(OperationalLogger::class),
                $app->make(Translator::class),
                $store instanceof CacheRepository ? $store : null,
                $throttle,
                $config->string('laranail.settings.cache.prefix', 'laranail-settings'),
            );
        });
    }

    /**
     * Typed settings (Phase 5): each class in `typed.classes` registers its definitions when the
     * definition registry is first built (and is bound at boot); settings migrations get their
     * migrator.
     */
    private function registerTyped(): void
    {
        $app = $this->app;

        $app->afterResolving(DefinitionRegistry::class, static function (DefinitionRegistry $registry) use ($app): void {
            foreach (self::strings($app->make(Repository::class), 'laranail.settings.typed.classes') as $class) {
                if (! is_subclass_of($class, TypedSettings::class)) {
                    throw new InvalidConfiguration(sprintf('laranail.settings.typed.classes: [%s] is not a typed settings class.', $class));
                }

                $class::register($registry);
            }
        });

        SettingsMigration::resolveMigratorUsing(static fn (): SettingsMigrator => new SettingsMigrator(
            $app->make(MigrateSettings::class),
            new StoreName($app->make(Repository::class)->string('laranail.settings.default', 'settings')),
            Actor::system('settings migration'),
        ));
    }
}
