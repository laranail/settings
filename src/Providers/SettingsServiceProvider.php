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
use Simtabi\Laranail\Settings\Audit\LogAuditSink;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Audit\AutoAuditSink;
use Simtabi\Laranail\Settings\Audit\NullAuditSink;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Resolution\Resolver;
use Simtabi\Laranail\Settings\Actions\PromoteStore;
use Simtabi\Laranail\Settings\Actions\PruneOrphans;
use Simtabi\Laranail\Settings\Actions\StoreActions;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
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
use Simtabi\Laranail\Settings\Authorization\ChainAuthorizer;
use Simtabi\Laranail\Settings\Database\SchemaReadinessProbe;
use Simtabi\Laranail\Settings\Support\LaravelValueEncrypter;
use Simtabi\Laranail\Settings\Support\LaravelValueValidator;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Authentication\GuardActorResolver;
use Simtabi\Laranail\Settings\Actions\Categories\CategoryActions;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Authentication\SanctumActorResolver;
use Simtabi\Laranail\Settings\Authorization\PermissionsAuthorizer;
use Simtabi\Laranail\Settings\Verification\ActorTimestampVerifier;
use Simtabi\Laranail\Package\Tools\Providers\PackageServiceProvider;
use Simtabi\Laranail\DbTools\Schema\Contracts\SchemaReadinessInterface;
use Simtabi\Laranail\Settings\Verification\PasswordConfirmationVerifier;

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
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laranail/settings')
            ->setPublishTagId('settings')
            ->hasConfigFile('settings')
            ->hasMigrations(['create_settings_categories_table', 'create_settings_table', 'create_settings_audit_table'])
            ->hasCommands([PromoteCommand::class, PruneOrphansCommand::class, PruneAuditCommand::class, MakeStoreCommand::class, DoctorCommand::class])
            ->hasInstallCommand(static function (InstallCommand $command): void {
                $command
                    ->startWith(static function (InstallCommand $command): void {
                        self::refuseForeignTables($command);
                    })
                    ->publishConfigFile()
                    ->publishMigrations();
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
        ));

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
        ));

        $this->app->singleton(SettingWriter::class, static fn (Application $app): SettingWriter => new SettingWriter(
            $app->make(ValuePipeline::class),
            $app->make(Dispatcher::class),
            self::clock(),
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
                        ?? $app->make(DefinitionRegistry::class)->find($store->name, $key),
                ),
                $flush,
            );
        });

        $this->app->scoped(PruneOrphans::class, static fn (Application $app): PruneOrphans => new PruneOrphans(
            $app->make(StoreRegistry::class),
            $app->make(StoreActions::class)->flush,
            static fn (string $alias): ?string => Relation::getMorphedModel($alias),
        ));

        $this->app->bind(MakeStore::class, static fn (Application $app): MakeStore => new MakeStore(
            $app->make(Filesystem::class),
            dirname(__DIR__, 2) . '/database/migrations',
            $app->databasePath('migrations'),
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
        $value = $app->make(Repository::class)->get($key, $default);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }

    /** @return list<string> */
    private static function strings(Application $app, string $key): array
    {
        return array_values(array_filter(self::array($app, $key), is_string(...)));
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
