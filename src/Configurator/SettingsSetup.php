<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Closure;
use Throwable;
use ReflectionClass;
use ReflectionMethod;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Enums\Stage;
use Simtabi\Laranail\Settings\SettingsManager;
use Simtabi\Laranail\Settings\Contracts\Caster;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Contracts\Importer;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Contracts\Verifier;
use Simtabi\Laranail\Settings\Contracts\AuditSink;
use Simtabi\Laranail\Settings\Contracts\Authorizer;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Contracts\ActorResolver;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Definitions\DefinitionBuilder;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Package\Tools\Services\Doctor\DoctorCheck;

/**
 * What a configurator's `configure()` receives: typed, fluent setters for the package's
 * configuration. Each setter validates its value where it is called and records it under its
 * `laranail.settings` path; the package writes the recorded values into config before any of its
 * services is built, so requests, jobs, Artisan, migrations and `config:cache` all read them.
 */
final readonly class SettingsSetup
{
    public function __construct(private SetupValues $values) {}

    /** `default`: the store `Settings::get()` reads without `->store()`. */
    public function defaultStore(string $name): self
    {
        $this->values->applicationOnly('defaultStore()');
        $this->values->set('default', SetupValues::name('defaultStore()', 'default', $name));

        return $this;
    }

    /** `bootstrap`: skip the database layer of every store while the application installs. */
    public function bootstrap(bool $installing = true): self
    {
        $this->values->set('bootstrap', $installing);

        return $this;
    }

    /** `promote`: `manual` or `auto` for every store, or null to leave it to each store. */
    public function promote(?string $mode): self
    {
        $this->values->set('promote', $mode === null ? null : SetupValues::oneOf('promote()', 'promote', $mode, ['manual', 'auto']));

        return $this;
    }

    /**
     * `tables.*`: the prefix and the shared table names; arguments left null keep their value.
     */
    public function tables(
        ?string $prefix = null,
        ?string $categories = null,
        ?string $audit = null,
        ?string $definitions = null,
        ?string $history = null,
        ?string $changeRequests = null,
        ?string $stores = null,
    ): self {
        $this->values->applicationOnly('tables()');

        if ($prefix !== null) {
            $this->values->set('tables.prefix', $this->validated(static fn (): string => new SchemaNames(prefix: $prefix)->prefix, 'tables()', 'tables.prefix'));
        }

        foreach (['categories' => $categories, 'audit' => $audit, 'definitions' => $definitions, 'history' => $history, 'change_requests' => $changeRequests, 'stores' => $stores] as $key => $table) {
            if ($table !== null) {
                $this->validated(static fn () => SchemaNames::assertTable($table, 'laranail.settings.tables.' . $key), 'tables()', 'tables.' . $key);
                $this->values->set('tables.' . $key, $table);
            }
        }

        return $this;
    }

    /**
     * `stores.<name>.*`: add a store or change one.
     *
     * @param Closure(StoreSetup): mixed $setup
     */
    public function store(string $name, Closure $setup): self
    {
        $setup(new StoreSetup($this->values, 'stores.' . SetupValues::name('store()', 'stores', $name)));

        return $this;
    }

    /**
     * `layers.<name>.*`: add a layer or change one.
     *
     * @param Closure(LayerSetup): mixed $setup
     */
    public function layer(string $name, Closure $setup): self
    {
        $setup(new LayerSetup($this->values, 'layers.' . SetupValues::name('layer()', 'layers', $name)));

        return $this;
    }

    /** @param Closure(CacheSetup): mixed $setup `cache.*` */
    public function cache(Closure $setup): self
    {
        $setup(new CacheSetup($this->values));

        return $this;
    }

    /** @param Closure(TenancySetup): mixed $setup `tenancy.*` */
    public function tenancy(Closure $setup): self
    {
        $this->values->applicationOnly('tenancy()');
        $setup(new TenancySetup($this->values));

        return $this;
    }

    /** @param Closure(SeedSetup): mixed $setup `seed.*` */
    public function seed(Closure $setup): self
    {
        $setup(new SeedSetup($this->values));

        return $this;
    }

    /** @param Closure(ValuesSetup): mixed $setup `values.*` and `writes.*` */
    public function values(Closure $setup): self
    {
        $setup(new ValuesSetup($this->values));

        return $this;
    }

    /** @param Closure(HttpSetup): mixed $setup `http.*` */
    public function http(Closure $setup): self
    {
        $this->values->applicationOnly('http()');
        $setup(new HttpSetup($this->values));

        return $this;
    }

    /** @param Closure(AuditSetup): mixed $setup `audit.*` and `history.*` */
    public function audit(Closure $setup): self
    {
        $setup(new AuditSetup($this->values));

        return $this;
    }

    /** @param Closure(ApprovalsSetup): mixed $setup `approvals.*` */
    public function approvals(Closure $setup): self
    {
        $setup(new ApprovalsSetup($this->values));

        return $this;
    }

    /** @param Closure(LoggingSetup): mixed $setup `logging.*` */
    public function logging(Closure $setup): self
    {
        $setup(new LoggingSetup($this->values));

        return $this;
    }

    /** @param Closure(AlertsSetup): mixed $setup `alerts.*` */
    public function alerts(Closure $setup): self
    {
        $setup(new AlertsSetup($this->values));

        return $this;
    }

    /**
     * Who may contribute: `Loose` (the default) applies packages, then modules, then this
     * configurator; `Strict` applies this configurator only and reports each refused contributor.
     * Application only.
     */
    public function mode(ConfiguratorMode $mode): self
    {
        $this->values->setMode($mode);

        return $this;
    }

    /**
     * Leave a package (by Composer name) or a module (by module name) out entirely, on the record:
     * nothing it declares is applied, and the names it would have owned are free for this
     * configurator. Application only.
     */
    public function exclude(?string $package = null, ?string $module = null): self
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $site = ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0);

        if (($package === null) === ($module === null)) {
            $this->values->applicationOnly('exclude()');

            throw new InvalidConfiguration('exclude(): name exactly one package or one module.');
        }

        $this->values->exclude($package !== null ? ConfiguratorScope::Package : ConfiguratorScope::Module, (string) ($package ?? $module), $site);

        return $this;
    }

    /** `configuration.persisted`: read store and layer configuration saved in the database. */
    public function persistedConfiguration(bool $enabled = true): self
    {
        $this->values->set('configuration.persisted', $enabled);

        return $this;
    }

    /**
     * A custom storage driver for layers to name (`drivers.<name>`), for every request. A
     * request's `configuration()->extendDriver()` still adds its own on top.
     *
     * @param Closure(LayerConfig): Driver $factory
     */
    public function driver(string $name, Closure $factory): self
    {
        SetupValues::name('driver()', 'drivers', $name);

        if (DriverType::tryFrom($name) !== null) {
            throw SetupValues::invalid('driver()', 'drivers.' . $name, 'a name that is not a built-in driver', $name);
        }

        $this->values->behave('drivers.' . $name, $factory);

        return $this;
    }

    /** A caster for definitions to name as their type (`casters.<alias>`). */
    public function caster(string $alias, Caster $caster): self
    {
        if (preg_match('/^[a-z][a-z0-9_:]*$/', $alias) !== 1) {
            throw SetupValues::invalid('caster()', 'casters.' . $alias, 'an alias matching [a-z][a-z0-9_:]*', $alias);
        }

        $this->values->behave('casters.' . $alias, $caster);

        return $this;
    }

    /**
     * An importer the import command and `import()` can name (`importers.<name>`).
     *
     * @param class-string<Importer> $class
     */
    public function importer(string $name, string $class): self
    {
        SetupValues::name('importer()', 'importers', $name);

        if (! is_subclass_of($class, Importer::class)) {
            throw SetupValues::invalid('importer()', 'importers.' . $name, 'a class implementing ' . Importer::class, $class);
        }

        $this->values->set('importers.' . $name, $class);

        return $this;
    }

    /**
     * A setting's definition, in code (`definitions.<key>`).
     *
     * @param Closure(DefinitionBuilder): mixed $setup
     */
    public function define(string $key, Closure $setup): self
    {
        try {
            $key = new Key($key)->value;
        } catch (Throwable $exception) {
            throw new InvalidConfiguration('define() (laranail.settings.definitions): ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $this->values->behave('definitions.' . $key, $setup);

        return $this;
    }

    /**
     * Who may do what (`authorization.driver`): an authorizer, a closure, or a class built through
     * the container. Application only.
     *
     * @param Authorizer|Closure|class-string<Authorizer> $authorizer
     */
    public function authorizeUsing(Authorizer|Closure|string $authorizer): self
    {
        $this->values->applicationOnly('authorizeUsing()');
        $this->values->behave('authorization.driver', $this->contract('authorizeUsing()', 'authorization.driver', $authorizer, Authorizer::class));

        return $this;
    }

    /**
     * Step-up verification (`verification.driver`): a verifier, a closure, or a class built
     * through the container.
     *
     * @param Verifier|Closure|class-string<Verifier> $verifier
     */
    public function verifyUsing(Verifier|Closure|string $verifier): self
    {
        $this->values->behave('verification.driver', $this->contract('verifyUsing()', 'verification.driver', $verifier, Verifier::class));

        return $this;
    }

    /**
     * Who is acting (`authentication.resolver`): a resolver, a closure, or a class built through
     * the container. Application only.
     *
     * @param ActorResolver|Closure|class-string<ActorResolver> $resolver
     */
    public function resolveActorsUsing(ActorResolver|Closure|string $resolver): self
    {
        $this->values->applicationOnly('resolveActorsUsing()');
        $this->values->behave('authentication.resolver', $this->contract('resolveActorsUsing()', 'authentication.resolver', $resolver, ActorResolver::class));

        return $this;
    }

    /** `authorization.require_actor`: refuse calls without an actor. Application only. */
    public function requireActor(bool $required = true): self
    {
        $this->values->applicationOnly('requireActor()');
        $this->values->set('authorization.require_actor', $required);

        return $this;
    }

    /**
     * Send audit entries to these sinks as well as the configured ones. Additive: every owner's
     * sinks are kept, in order.
     *
     * @param AuditSink|class-string<AuditSink> ...$sinks
     */
    public function auditTo(AuditSink|string ...$sinks): self
    {
        foreach ($sinks as $sink) {
            $this->values->append('audit.sinks', $this->contract('auditTo()', 'audit.sinks', $sink, AuditSink::class));
        }

        return $this;
    }

    /** Send the operational log to this logger, or nowhere with `false` (`logging.channel`). */
    public function logTo(LoggerInterface|false $logger): self
    {
        $this->values->behave('logging.channel', $logger);

        return $this;
    }

    /**
     * Wrap the settings in a decorator. The closure receives the settings and returns a
     * `SettingsDecorator`; the last one added wraps outermost (packages, then modules, then the
     * application). Additive: every owner's decorators are kept.
     *
     * @param Closure(Settings): SettingsDecorator $decorator
     */
    public function decorate(Closure $decorator): self
    {
        $this->values->append('decorators', $decorator);

        return $this;
    }

    /**
     * A value stage at one of the two positions: `BeforeWrite` sees the value a caller sets, before
     * validation, casting and encryption; `AfterRead` sees the value a read returns, after
     * decryption and casting and before redaction. A stage never sees ciphertext and cannot skip
     * validation. Additive, in the order added.
     *
     * @param Closure(mixed, Key): mixed $pipe
     */
    public function pipe(Stage $stage, Closure $pipe): self
    {
        $this->values->append('pipes.' . $stage->value, $pipe);

        return $this;
    }

    /**
     * Listen to one of the package's events. Additive: listeners on one event run in the order
     * added.
     *
     * @param Closure|class-string $listener
     */
    public function on(string $event, Closure|string $listener): self
    {
        if (! class_exists($event)) {
            throw SetupValues::invalid('on()', 'listeners', 'an event class', $event);
        }

        if (is_string($listener) && ! class_exists($listener)) {
            throw SetupValues::invalid('on()', 'listeners', 'a listener class', $listener);
        }

        $this->values->append('listeners', [$event, $listener]);

        return $this;
    }

    /** A method added to every settings handle (`macros.<name>`). Macros are static: they last for the process. */
    public function macro(string $name, Closure $macro): self
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1 || method_exists(SettingsManager::class, $name)) {
            throw SetupValues::invalid('macro()', 'macros.' . $name, 'a method name SettingsManager does not have', $name);
        }

        $this->values->behave('macros.' . $name, $macro);

        return $this;
    }

    /** Each public or protected method of the mixin, which returns a closure, as a macro (as Laravel's `mixin()`). */
    public function mixin(object $mixin): self
    {
        foreach (new ReflectionClass($mixin)->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $macro = $method->invoke($mixin);

            if (! $macro instanceof Closure) {
                throw SetupValues::invalid('mixin()', 'macros.' . $method->name, 'a method returning a closure', get_debug_type($macro));
            }

            $this->macro($method->name, $macro);
        }

        return $this;
    }

    /** A check `laranail::settings.doctor` runs with its own. Additive. */
    public function doctorCheck(DoctorCheck $check): self
    {
        $this->values->append('doctor.checks', $check);

        return $this;
    }

    /**
     * An instance or closure as given, or a class name that implements the contract.
     *
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return T|Closure|class-string<T>
     */
    private function contract(string $method, string $path, object|string $value, string $contract): object|string
    {
        if ($value instanceof Closure || $value instanceof $contract) {
            return $value;
        }

        if (is_object($value)) {
            throw SetupValues::invalid($method, $path, 'a closure or a ' . $contract, $value);
        }

        return is_subclass_of($value, $contract) ? $value : throw SetupValues::invalid($method, $path, 'a class implementing ' . $contract, $value);
    }

    /**
     * Runs a validator that names only the config path, and names the setup method too.
     *
     * @template T
     *
     * @param Closure(): T $check
     *
     * @return T
     */
    private function validated(Closure $check, string $method, string $path): mixed
    {
        try {
            return $check();
        } catch (InvalidConfiguration $exception) {
            throw new InvalidConfiguration($method . ' (laranail.settings.' . $path . '): ' . $exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }
}
