<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

use Closure;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Enums\ConfiguratorMode;
use Simtabi\Laranail\Settings\Enums\ConfiguratorScope;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

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
