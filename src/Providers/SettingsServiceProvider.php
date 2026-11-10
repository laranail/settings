<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Providers;

use Closure;
use Override;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Filesystem\Filesystem;
use Simtabi\Laranail\Settings\Data\Key;
use Illuminate\Contracts\Session\Session;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Package\Tools\Package;
use Simtabi\Laranail\Settings\Stores\Store;
use Illuminate\Contracts\Validation\Factory;
use Simtabi\Laranail\Settings\Audit\Auditor;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\SettingsManager;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Contracts\Foundation\Application;
use Simtabi\Laranail\Settings\Actions\MakeStore;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Illuminate\Contracts\Cache\Factory as Caches;
use Simtabi\Laranail\Settings\Actions\FlushScope;
use Simtabi\Laranail\Settings\Actions\PruneAudit;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Audit\LogAuditSink;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Audit\AutoAuditSink;
use Simtabi\Laranail\Settings\Audit\NullAuditSink;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Models\ModelContext;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Actions\PromoteStore;
use Simtabi\Laranail\Settings\Actions\PruneOrphans;
use Simtabi\Laranail\Settings\Actions\StoreActions;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\ConfigValues;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Console\DoctorCommand;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Support\ValuePipeline;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Filesystem\Factory as Disks;
use Simtabi\Laranail\Settings\Actions\SettingActions;
use Simtabi\Laranail\Settings\Casting\CasterRegistry;
use Simtabi\Laranail\Settings\Console\PromoteCommand;
use Simtabi\Laranail\Settings\Drivers\ReadOnlyValues;
use Simtabi\Laranail\Settings\Actions\SyncDefinitions;
use Simtabi\Laranail\Settings\Audit\DatabaseAuditSink;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Console\MakeStoreCommand;
use Simtabi\Laranail\Settings\Contracts\ReadinessProbe;
use Simtabi\Laranail\Settings\Contracts\ValueEncrypter;
use Simtabi\Laranail\Settings\Contracts\ValueValidator;
use Simtabi\Laranail\Settings\Support\RecordSerializer;
use Simtabi\Laranail\Settings\Actions\FindForeignTables;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Console\PruneAuditCommand;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Verification\NullVerifier;
use Simtabi\Laranail\Settings\Console\PruneOrphansCommand;
use Simtabi\Laranail\Settings\Contracts\BootstrapEvidence;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Package\Tools\Commands\InstallCommand;
use Simtabi\Laranail\Settings\Authorization\GateAuthorizer;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Authorization\ChainAuthorizer;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Database\SchemaReadinessProbe;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Support\LaravelValueValidator;
use Simtabi\Laranail\Settings\Console\SyncDefinitionsCommand;
use Simtabi\Laranail\Settings\Database\SchemaShapeRepository;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Definitions\DatabaseDefinitions;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Tenancy\NullTenantDiscriminator;
use Simtabi\Laranail\Settings\Authentication\GuardActorResolver;
use Simtabi\Laranail\Settings\Actions\Categories\CategoryActions;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;
use Simtabi\Laranail\Settings\Authentication\SanctumActorResolver;
use Simtabi\Laranail\Settings\Authorization\PermissionsAuthorizer;
use Simtabi\Laranail\Settings\Verification\ActorTimestampVerifier;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\Settings\Database\Seeders\DefaultCategoriesSeeder;
use Simtabi\Laranail\Settings\Verification\PasswordConfirmationVerifier;
use Simtabi\Laranail\Package\Tools\Support\Definitions\AutoSeederDefinition;

/**
 * Registers laranail/settings with the host application.
 *
 * Every name it registers is vendor-scoped: config at `laranail.settings`, publish tags
 * `laranail::settings-*`, and the install command `laranail::settings.install`. Nothing here
 * touches the database or the filesystem at boot: stores, drivers and definitions are built on
 * first use, and the encrypter is resolved only when a value is encrypted or decrypted.
 *
 * Everything derived from a request (the actor resolver, the runtime configuration, the access
 * guard, the auditor and the log) is bound `scoped`, so Octane and queue workers start each
 * request and job clean. Configuration naming an unknown driver, sink or level raises
 * InvalidConfiguration with its config path on first use.
 */
final class SettingsServiceProvider extends PackageServiceProvider
{
    private const string STANCL = 'Stancl\\Tenancy\\Tenancy';

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/settings')
            ->setPublishTagId('settings')
            ->hasConfigFile('settings')
            ->hasMigrations(['create_laranail_settings_tables'])
            // Runs with the host's `php artisan db:seed`; the seeder itself honours seed.enabled.
            // Foreign keys stay checked (a child category needs its parent) and events fire (the
            // settings API dispatches CategorySaving/Saved and writes the audit trail).
            ->hasPackageSeeders(AutoSeederDefinition::make('laranail/settings:defaults')
                ->seeders([DefaultCategoriesSeeder::class])
                ->options(['disable_foreign_key_checks' => false, 'fire_events' => true]))
            ->hasCommands([PromoteCommand::class, PruneOrphansCommand::class, PruneAuditCommand::class, MakeStoreCommand::class, DoctorCommand::class, SyncDefinitionsCommand::class])
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
    public function packageBooted(): void
    {
        // db-tools memoises readiness in a singleton: forget it once the schema may have changed
        // and at the start of every queued job (and Octane request, when Octane is installed).
        $app = $this->app;
        $flush = static function () use ($app): void {
            $app->make(ReadinessProbe::class)->flush();
        };
        $events = $app->make(Dispatcher::class);

        $events->listen(MigrationsEnded::class, $flush);
        $events->listen(JobProcessing::class, $flush);
        $events->listen('Laravel\\Octane\\Events\\RequestReceived', $flush);

        // Tenant switches (db-tools has no hook of its own): the tenancy packages' events, by name.
        foreach (self::strings($app, 'laranail.settings.readiness.flush_on') as $event) {
            $events->listen($event, $flush);
        }
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->registerTenancy();

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
            in_array('config', self::definitionSources($app), true) ? self::array($app, 'laranail.settings.definitions') : [],
        ));

        $this->app->singleton(ReadinessProbe::class, static fn (Application $app): ReadinessProbe => new SchemaReadinessProbe(
            $app->make(SchemaReadinessInterface::class),
        ));

        $this->app->singleton(DriverFactory::class, static fn (Application $app): DriverFactory => new DriverFactory(
            $app->make(Disks::class),
            $app->make(Repository::class),
            new ReadOnlyValues($app->make(ValueEncoder::class)),
            $app->make(RecordSerializer::class),
            $app->basePath(),
            $app->make(ConnectionResolverInterface::class),
            SchemaNames::fromConfig(self::array($app, 'laranail.settings.tables')),
            static fn (): ReadinessProbe => $app->make(ReadinessProbe::class),
            static fn (): SchemaShape => $app->make(SchemaShapeRepository::class)->current(),
            static fn (): ?string => $app->make(TenantDiscriminator::class)->current(),
        ));

        // The recorded schema shape (§4.0). Scoped: read once per request or job, never at boot.
        $this->app->scoped(SchemaShapeRepository::class, static function (Application $app): SchemaShapeRepository {
            $path = $app->make(Repository::class)->get('laranail.settings.schema.path');

            return new SchemaShapeRepository(
                $app->make(Filesystem::class),
                is_string($path) && $path !== '' ? $path : $app->databasePath('settings-schema.php'),
                static fn (): SchemaShape => SchemaShape::fromConfig(
                    self::array($app, 'laranail.settings'),
                    $app->make(Repository::class)->get('laranail.db-tools.id_type'),
                ),
            );
        });

        // Evidence that the application is being installed: the operator's flag, or
        // laranail/installer-headless (suggested, so class_exists-guarded) reporting an install
        // in progress. Read on first use, never at boot.
        $this->app->scoped(BootstrapEvidence::class, static function (Application $app): BootstrapEvidence {
            $installer = 'Simtabi\\Laranail\\Installer\\Headless\\Support\\InstallationState';

            return new InstallationEvidence(
                $app->make(Repository::class)->get('laranail.settings.bootstrap', false) === true,
                class_exists($installer) ? static function () use ($app, $installer): bool {
                    $state = $app->make($installer);

                    return is_object($state) && method_exists($state, 'isInstalling') && $state->isInstalling() === true;
                } : null,
            );
        });

        // Scoped: rebuilt per request and per queued job under Octane and Horizon, so no
        // per-request state can survive into the next one.
        $this->app->scoped(RuntimeConfiguration::class, static fn (Application $app): RuntimeConfiguration => new RuntimeConfiguration(
            $app->make(DriverFactory::class),
            self::string($app, 'laranail.settings.default', 'settings'),
            self::array($app, 'laranail.settings.stores'),
            self::array($app, 'laranail.settings.layers'),
            $app->make(CasterRegistry::class),
            static function (string $change) use ($app): void {
                $app->make(OperationalLogger::class)->info('laranail/settings runtime configuration: ' . $change);
                $app->make(Auditor::class)->record(AuditAction::Configure, AuditOutcome::Allowed, null, null, meta: ['change' => $change]);
            },
        ));

        $this->app->scoped(StoreRegistry::class, static fn (Application $app): StoreRegistry => new StoreRegistry(
            $app->make(DriverFactory::class),
            self::string($app, 'laranail.settings.default', 'settings'),
            self::array($app, 'laranail.settings.stores'),
            self::array($app, 'laranail.settings.layers'),
            runtime: $app->make(RuntimeConfiguration::class),
            evidence: $app->make(BootstrapEvidence::class),
            onLayerSkipped: static function (string $store, string $layer, string $reason) use ($app): void {
                $app->make(Dispatcher::class)->dispatch(new LayerSkipped($store, $layer, $reason));
            },
        ));

        $this->app->singleton(ValuePipeline::class);

        $this->app->scoped(Resolver::class, static fn (Application $app): Resolver => new Resolver(
            $app->make(StoreRegistry::class),
            $app->make(ValuePipeline::class),
            self::string($app, 'app.fallback_locale', 'en'),
            tenant: $app->make(TenantDiscriminator::class),
        ));

        $this->app->singleton(SettingWriter::class, static fn (Application $app): SettingWriter => new SettingWriter(
            $app->make(ValuePipeline::class),
            $app->make(Dispatcher::class),
            self::clock(),
            $app->make(Repository::class)->get('laranail.settings.writes.retry_on_conflict', true) !== false,
            static function (Store $store, Key $key, Scope $scope) use ($app): void {
                $app->make(OperationalLogger::class)->info(sprintf('laranail/settings retried a write of [%s] in store [%s] for scope [%s] after a concurrent change.', $key, $store->name, $scope), [
                    'store' => $store->name->value,
                    'key'   => $key->value,
                    'scope' => $scope->value,
                ]);
            },
        ));

        $this->registerAccess();

        $this->app->scoped(SettingActions::class, static fn (Application $app): SettingActions => SettingActions::using(
            $app->make(SettingWriter::class),
            $app->make(AccessGuard::class),
        ));

        $this->app->scoped(StoreActions::class, static function (Application $app): StoreActions {
            $access = $app->make(AccessGuard::class);
            $events = $app->make(Dispatcher::class);
            $flush = new FlushScope($access, $events);

            return new StoreActions(
                CategoryActions::using($access, $events),
                new PromoteStore(
                    $app->make(ValuePipeline::class),
                    $access,
                    $events,
                    $app->make(Caches::class),
                    static fn (Store $store, Key $key): ?Definition => $app->make(RuntimeConfiguration::class)->findDefinition($store->name, $key)
                        ?? $app->make(DefinitionRegistry::class)->find($store->name, $key)
                        ?? $app->make(DatabaseDefinitions::class)->find($store, $key),
                ),
                $flush,
            );
        });

        $this->app->scoped(DatabaseDefinitions::class, static fn (Application $app): DatabaseDefinitions => new DatabaseDefinitions(
            $app->make(CasterRegistry::class),
            $app->make(ValueEncoder::class),
            self::strings($app, 'laranail.settings.definitions.allowed_rules'),
            in_array('database', self::definitionSources($app), true),
        ));

        $this->app->scoped(SyncDefinitions::class, static fn (Application $app): SyncDefinitions => new SyncDefinitions(
            $app->make(StoreRegistry::class),
            $app->make(DefinitionRegistry::class),
            $app->make(ValueEncoder::class),
            $app->make(AccessGuard::class),
        ));

        $this->app->scoped(QueryAudit::class, static fn (Application $app): QueryAudit => new QueryAudit(
            $app->make(StoreRegistry::class),
            $app->make(AccessGuard::class),
        ));

        $this->app->scoped(PruneOrphans::class, static fn (Application $app): PruneOrphans => new PruneOrphans(
            $app->make(StoreRegistry::class),
            $app->make(StoreActions::class)->flush,
            static fn (string $alias): ?string => Relation::getMorphedModel($alias),
            $app->make(AccessGuard::class),
        ));

        $this->app->scoped(PruneAudit::class, static fn (Application $app): PruneAudit => new PruneAudit(
            $app->make(StoreRegistry::class),
            $app->make(AccessGuard::class),
        ));

        $this->app->bind(MakeStore::class, static fn (Application $app): MakeStore => new MakeStore(
            $app->make(Filesystem::class),
            dirname(__DIR__, 2) . '/database/migrations/' . MakeStore::MIGRATION . '.php',
            $app->databasePath('migrations'),
            $app->make(SchemaShapeRepository::class),
        ));

        $this->app->scoped(Settings::class, static fn (Application $app): Settings => new SettingsManager(
            $app->make(StoreRegistry::class),
            $app->make(DefinitionRegistry::class),
            $app->make(Resolver::class),
            $app->make(SettingWriter::class),
            actions: $app->make(SettingActions::class),
            access: $app->make(AccessGuard::class),
            runtime: $app->make(RuntimeConfiguration::class),
            storeActions: $app->make(StoreActions::class),
            auditQuery: $app->make(QueryAudit::class),
            databaseDefinitions: $app->make(DatabaseDefinitions::class),
        ));
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
     * `definitions.sources` (D-6): which of `code`, `config` and `database` are read. Code
     * definitions are registered by packages and the application in code and are always read.
     *
     * @return list<string>
     */
    private static function definitionSources(Application $app): array
    {
        $sources = $app->make(Repository::class)->get('laranail.settings.definitions.sources', ['code', 'config', 'database']);

        $names = is_array($sources) ? array_values(array_filter($sources, is_string(...))) : null;

        if ($names === null || count($names) !== count($sources) || array_diff($names, ['code', 'config', 'database']) !== []) {
            throw new InvalidConfiguration('laranail.settings.definitions.sources: a list of code, config and database.');
        }

        return $names;
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

    private static function authorizer(Application $app, mixed $driver, string $path): Authorizer
    {
        return match ($driver) {
            'gate'        => new GateAuthorizer($app->make(Gate::class)),
            'permissions' => new PermissionsAuthorizer(self::array($app, 'laranail.settings.authorization.permissions')),
            default       => self::instance($app, $driver, Authorizer::class, $path),
        };
    }

    private static function auditSink(Application $app, mixed $sink, string $path): AuditSink
    {
        return match ($sink) {
            'auto'     => new AutoAuditSink(self::databaseSink($app), new LogAuditSink($app->make(OperationalLogger::class))),
            'database' => self::databaseSink($app),
            'log'      => new LogAuditSink($app->make(OperationalLogger::class)),
            'null'     => new NullAuditSink,
            default    => self::instance($app, $sink, AuditSink::class, $path),
        };
    }

    private static function databaseSink(Application $app): DatabaseAuditSink
    {
        return new DatabaseAuditSink(static fn (): StoreRegistry => $app->make(StoreRegistry::class));
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

    /** @return Closure(): CarbonImmutable */
    private static function clock(): Closure
    {
        return static fn (): CarbonImmutable => now()->toImmutable();
    }

    private static function integer(Application $app, string $key, int $default): int
    {
        return self::config($app)->integer($key, $default);
    }

    /** @return list<string> */
    private static function strings(Application $app, string $key): array
    {
        return self::config($app)->strings($key);
    }

    private static function string(Application $app, string $key, string $default): string
    {
        return self::config($app)->string($key, $default);
    }

    /** @return array<array-key, mixed> */
    private static function array(Application $app, string $key): array
    {
        return self::config($app)->array($key);
    }

    private static function config(Application $app): ConfigValues
    {
        return new ConfigValues($app->make(Repository::class));
    }

    /** stancl/tenancy's current tenant key, or null when tenancy is not initialised. */
    private static function stanclTenant(Application $app): mixed
    {
        $tenancy = $app->make(self::STANCL);

        if (! is_object($tenancy) || ! property_exists($tenancy, 'initialized') || $tenancy->initialized !== true || ! property_exists($tenancy, 'tenant')) {
            return null;
        }

        $tenant = $tenancy->tenant;

        return is_object($tenant) && method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : null;
    }

    /** spatie/laravel-multitenancy's current tenant key (its container binding), or null. */
    private static function spatieTenant(Application $app): mixed
    {
        $binding = $app->make(Repository::class)->get('multitenancy.current_tenant_container_key', 'currentTenant');

        if (! is_string($binding) || ! $app->bound($binding)) {
            return null;
        }

        $tenant = $app->make($binding);

        return is_object($tenant) && method_exists($tenant, 'getKey') ? $tenant->getKey() : null;
    }

    /**
     * Tenancy (Amendment B): who the current tenant is, and the context the Eloquent models read
     * it from. `tenancy.discriminator`: `auto` (stancl/tenancy, then spatie/laravel-multitenancy,
     * when installed; otherwise none), `null`, `stancl`, `spatie`, or a class implementing
     * TenantDiscriminator. Bound `scoped`: the tenant changes between requests and jobs.
     */
    private function registerTenancy(): void
    {
        $this->app->scoped(TenantDiscriminator::class, static function (Application $app): TenantDiscriminator {
            $driver = $app->make(Repository::class)->get('laranail.settings.tenancy.discriminator', 'auto');
            $path = 'laranail.settings.tenancy.discriminator';

            if ($driver === 'auto') {
                $driver = match (true) {
                    class_exists(self::STANCL)                           => 'stancl',
                    class_exists('Spatie\\Multitenancy\\Models\\Tenant') => 'spatie',
                    default                                              => 'null',
                };
            }

            return match ($driver) {
                'null', null => new NullTenantDiscriminator,
                'stancl'     => class_exists(self::STANCL)
                    ? new ClosureTenantDiscriminator(static fn (): mixed => self::stanclTenant($app))
                    : throw new InvalidConfiguration($path . ': [stancl] needs stancl/tenancy.'),
                'spatie' => new ClosureTenantDiscriminator(static fn (): mixed => self::spatieTenant($app)),
                default  => self::instance($app, $driver, TenantDiscriminator::class, $path),
            };
        });

        $app = $this->app;
        ModelContext::use(
            static fn (): SchemaShape => $app->make(SchemaShapeRepository::class)->current(),
            static fn (): ?string => $app->make(TenantDiscriminator::class)->current(),
            static fn (): string => self::string($app, 'laranail.settings.default', 'settings'),
        );
    }

    /** Actor resolution, authorization, verification, audit and the operational log. */
    private function registerAccess(): void
    {
        $this->app->scoped(OperationalLogger::class, static function (Application $app): OperationalLogger {
            $channel = $app->make(Repository::class)->get('laranail.settings.logging.channel');

            return new OperationalLogger(
                $channel === false ? new NullLogger : $app->make(LogManager::class)->channel(is_string($channel) ? $channel : null),
                self::string($app, 'laranail.settings.logging.level', LogLevel::INFO),
                $app->make(RuntimeConfiguration::class),
            );
        });

        $this->app->scoped(Auditor::class, static fn (Application $app): Auditor => new Auditor(
            array_map(
                static fn (mixed $sink, int|string $index): AuditSink => self::auditSink($app, $sink, 'laranail.settings.audit.sinks.' . $index),
                self::array($app, 'laranail.settings.audit.sinks'),
                array_keys(self::array($app, 'laranail.settings.audit.sinks')),
            ),
            self::clock(),
            $app->make(RuntimeConfiguration::class),
        ));

        $this->app->scoped(Authorizer::class, static function (Application $app): Authorizer {
            $driver = $app->make(Repository::class)->get('laranail.settings.authorization.driver', 'gate');

            if (is_array($driver)) {
                return new ChainAuthorizer(array_values(array_map(
                    static fn (mixed $name, int|string $index): Authorizer => self::authorizer($app, $name, 'laranail.settings.authorization.driver.' . $index),
                    $driver,
                    array_keys($driver),
                )));
            }

            return self::authorizer($app, $driver, 'laranail.settings.authorization.driver');
        });

        $this->app->scoped(Verifier::class, static function (Application $app): Verifier {
            $driver = $app->make(Repository::class)->get('laranail.settings.verification.driver', 'password_confirmation');

            return match ($driver) {
                'password_confirmation' => new PasswordConfirmationVerifier(
                    static fn (): ?Session => $app->bound('request') && $app->make('request') instanceof Request && $app->make('request')->hasSession()
                        ? $app->make('request')->session()
                        : null,
                    self::integer($app, 'auth.password_timeout', 10800),
                    self::clock(),
                ),
                'actor' => new ActorTimestampVerifier(self::integer($app, 'laranail.settings.verification.ttl', 900), self::clock()),
                'none'  => new NullVerifier,
                default => self::instance($app, $driver, Verifier::class, 'laranail.settings.verification.driver'),
            };
        });

        $this->app->scoped(ActorResolver::class, static function (Application $app): ActorResolver {
            $resolver = $app->make(Repository::class)->get('laranail.settings.authentication.resolver', 'guard');
            $guards = self::strings($app, 'laranail.settings.authentication.guards');
            $request = static fn (): ?Request => $app->bound('request') && $app->make('request') instanceof Request ? $app->make('request') : null;

            return match ($resolver) {
                'guard'   => new GuardActorResolver($app->make(AuthFactory::class), $guards, $request),
                'sanctum' => new SanctumActorResolver($app->make(AuthFactory::class), $guards, $request),
                default   => self::instance($app, $resolver, ActorResolver::class, 'laranail.settings.authentication.resolver'),
            };
        });

        $this->app->scoped(AccessGuard::class, static function (Application $app): AccessGuard {
            $undefined = $app->make(Repository::class)->get('laranail.settings.authorization.undefined', 'deny');

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
                    static fn (string $ability): Ability => Ability::tryFrom($ability)
                        ?? throw new InvalidConfiguration(sprintf('laranail.settings.verification.abilities: unknown ability [%s].', $ability)),
                    self::strings($app, 'laranail.settings.verification.abilities'),
                ),
                $undefined === 'allow',
                $app->make(Repository::class)->get('laranail.settings.authorization.require_actor', false) === true,
                $app->make(RuntimeConfiguration::class),
            );
        });
    }
}
