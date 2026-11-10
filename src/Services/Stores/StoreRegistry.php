<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Stores;

use Closure;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Stores\Layer;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Database\StoreSchema;
use Simtabi\Laranail\Settings\Support\ConfigValues;
use Simtabi\Laranail\Settings\Drivers\CachingDriver;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Contracts\BootstrapEvidence;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Settings\Drivers\BootstrapGatedDriver;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/**
 * Builds and validates every store from configuration on first use. Configuration errors are
 * raised here, naming the config path, rather than surfacing later as a wrong value.
 */
final class StoreRegistry
{
    /** @var array<string, Store>|null */
    private ?array $stores = null;

    /** The runtime and persisted configuration generations the built stores reflect. */
    private string $builtGeneration = '';

    /**
     * @param array<array-key, mixed> $storeConfig `laranail.settings.stores`
     * @param array<array-key, mixed> $layerConfig `laranail.settings.layers`
     * @param (Closure(string, string, string): void)|null $onLayerSkipped told (store, layer, reason) when bootstrap mode skips a layer
     * @param list<string> $sources `configuration.sources`: the merge order of config, database (persisted) and runtime entries, later wins
     */
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly string $default,
        private readonly array $storeConfig,
        private readonly array $layerConfig,
        private readonly ?Driver $override = null,
        private readonly ?RuntimeConfiguration $runtime = null,
        private readonly ?BootstrapEvidence $evidence = null,
        private readonly ?Closure $onLayerSkipped = null,
        private readonly ?SettingsCache $cache = null,
        private readonly ?PersistedConfiguration $persisted = null,
        private readonly array $sources = ['config', 'database', 'runtime'],
    ) {}

    /** A copy whose every layer is replaced by one driver (used by the fake). */
    public function withEveryLayer(Driver $driver): self
    {
        return new self($this->drivers, $this->default, $this->storeConfig, $this->layerConfig, $driver, $this->runtime, $this->evidence, $this->onLayerSkipped, $this->cache, $this->persisted, $this->sources);
    }

    /**
     * Build every store and every defined layer, raising InvalidConfiguration for the first
     * problem. Runtime configuration calls this before accepting a change.
     */
    public function validate(): void
    {
        $this->all();

        foreach (array_keys($this->layerConfigs()) as $name) {
            $this->layer((string) $name, 'laranail.settings');
        }
    }

    public function default(): Store
    {
        return $this->get(new StoreName($this->default));
    }

    public function get(StoreName $name): Store
    {
        return $this->all()[$name->value] ?? throw new UnknownStore(sprintf('Store [%s] is not configured in laranail.settings.stores.', $name));
    }

    /** Where a store's global values live: the store itself, or a dedicated store's fallback. */
    public function globalOf(Store $store): Store
    {
        return $store->fallback instanceof StoreName ? $this->get($store->fallback) : $store;
    }

    /** The one store whose owners include this alias. */
    public function forOwner(string $alias): Store
    {
        foreach ($this->all() as $store) {
            if ($store->accepts($alias)) {
                return $store;
            }
        }

        throw new OwnerNotAllowed(sprintf('No store lists owner alias [%s] in its owners.', $alias));
    }

    /** @return array<string, Store> */
    public function all(): array
    {
        $generation = ($this->runtime?->generation() ?? 0) . ':' . ($this->persisted?->generation() ?? 0);

        if ($this->stores === null || $this->builtGeneration !== $generation) {
            $this->stores = $this->build();
            $this->builtGeneration = $generation;
        }

        return $this->stores;
    }

    /** @return array<string, Store> */
    private function build(): array
    {
        $built = [];
        $claimed = [];

        foreach ($this->storeConfigs() as $name => $config) {
            $path = 'laranail.settings.stores.' . $name;

            if (! is_array($config)) {
                throw new InvalidConfiguration($path . ': an array is required.');
            }

            try {
                $storeName = new StoreName((string) $name);
            } catch (InvalidIdentifier $exception) {
                throw new InvalidConfiguration($path . ': ' . $exception->getMessage(), $exception->getCode(), previous: $exception);
            }

            $owners = $this->strings($config['owners'] ?? [], $path . '.owners');

            foreach ($owners as $alias) {
                if (isset($claimed[$alias])) {
                    throw new InvalidConfiguration(sprintf('%s.owners: alias [%s] is already owned by store [%s]; each alias belongs to one store.', $path, $alias, $claimed[$alias]));
                }

                $claimed[$alias] = $storeName->value;
            }

            $layerNames = $this->strings($config['layers'] ?? [], $path . '.layers');

            if ($layerNames === []) {
                throw new InvalidConfiguration($path . '.layers: at least one layer is required.');
            }

            $table = $config['table'] ?? null;

            if ($table !== null) {
                if (! is_string($table)) {
                    throw new InvalidConfiguration($path . '.table: a table name is required.');
                }

                SchemaNames::assertTable($table, $path . '.table');
            }

            $bootstrap = ($config['bootstrap'] ?? false) === true;
            $schema = StoreSchema::fromConfig($storeName, $config, null, IdType::Bigint, new StoreName($this->default));

            $built[$storeName->value] = new Store(
                name: $storeName,
                layers: array_map(fn (string $layer): Layer => $this->storeLayer($layer, $path, $storeName, $table, $bootstrap), $layerNames),
                owners: $owners,
                strict: ($config['strict'] ?? false) === true,
                inherits: ($config['inherits'] ?? false) === true,
                locksOverridable: $this->locksOverridable($config['locks'] ?? [], $path . '.locks'),
                bootstrap: $bootstrap,
                table: $table,
                mode: $schema->mode,
                fallback: $schema->fallback,
                approvalKeys: $this->approvalKeys($config['approvals'] ?? [], $path . '.approvals'),
            );
        }

        if (! isset($built[$this->default])) {
            throw new InvalidConfiguration(sprintf('laranail.settings.default: store [%s] is not configured.', $this->default));
        }

        foreach ($built as $store) {
            if ($store->fallback instanceof StoreName && ($built[$store->fallback->value] ?? null)?->isDedicated() !== false) {
                throw new InvalidConfiguration(sprintf('laranail.settings.stores.%s.fallback: [%s] must be a configured polymorphic store.', $store->name, $store->fallback));
            }
        }

        return $built;
    }

    /**
     * A layer built for one store: a database layer learns the store's table, in a store in
     * bootstrap mode it is put behind the bootstrap gate, and with caching on it is read through
     * the settings cache (outside the gate, so a skipped layer is never cached).
     */
    private function storeLayer(string $name, string $storePath, StoreName $store, ?string $table, bool $bootstrap): Layer
    {
        $layer = $this->gatedLayer($this->layer($name, $storePath, $table === null ? [] : ['table' => $table]), $store, $bootstrap);

        return $this->cache instanceof SettingsCache && $layer->driver instanceof DatabaseBacked
            ? new Layer($layer->name, new CachingDriver($layer->driver, $this->cache), $layer->type)
            : $layer;
    }

    private function gatedLayer(Layer $layer, StoreName $store, bool $bootstrap): Layer
    {
        $name = $layer->name;

        if (! $bootstrap || ! $layer->driver instanceof DatabaseDriver) {
            return $layer;
        }

        $onSkip = $this->onLayerSkipped;

        return new Layer($name, new BootstrapGatedDriver(
            $layer->driver,
            $store,
            $this->evidence ?? new InstallationEvidence(false),
            $onSkip instanceof Closure ? static function (string $reason) use ($onSkip, $store, $name): void {
                $onSkip($store->value, $name, $reason);
            } : null,
        ), $layer->type);
    }

    /** @param array<string, mixed> $extra options the store adds (the database table) */
    private function layer(string $name, string $storePath, array $extra = []): Layer
    {
        $config = $this->layerConfigs()[$name] ?? null;

        if (! is_array($config)) {
            throw new InvalidConfiguration(sprintf('%s.layers: layer [%s] is not defined in laranail.settings.layers.', $storePath, $name));
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver)) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.driver: a driver name is required.', $name));
        }

        $options = array_filter($config, is_string(...), ARRAY_FILTER_USE_KEY);
        $layer = new LayerConfig($name, $driver, ($config['writable'] ?? true) === true, $extra + $options);

        return new Layer($name, $this->override ?? $this->driverFactory()->make($layer), $this->override instanceof Driver ? DriverType::Array : DriverType::tryFrom($driver));
    }

    /** @return array<array-key, mixed> config, persisted and runtime stores, merged in `configuration.sources` order */
    private function storeConfigs(): array
    {
        return $this->merged($this->storeConfig, fn (): array => $this->persisted?->stores() ?? [], $this->runtime?->stores() ?? []);
    }

    /** @return array<array-key, mixed> config, persisted and runtime layers, merged in `configuration.sources` order */
    private function layerConfigs(): array
    {
        return $this->merged($this->layerConfig, fn (): array => $this->persisted?->layers() ?? [], $this->runtime?->layers() ?? []);
    }

    /**
     * @param array<array-key, mixed> $config
     * @param Closure(): array<array-key, mixed> $persisted read only when `database` is a source
     * @param array<array-key, mixed> $runtime
     *
     * @return array<array-key, mixed>
     */
    private function merged(array $config, Closure $persisted, array $runtime): array
    {
        $merged = [];

        foreach ($this->sources as $source) {
            $merged = array_replace($merged, match ($source) {
                'config'   => $config,
                'database' => $persisted(),
                'runtime'  => $runtime,
                default    => throw new InvalidConfiguration(sprintf('laranail.settings.configuration.sources: [%s] is not config, database or runtime.', $source)),
            });
        }

        return $merged;
    }

    private function driverFactory(): DriverFactory
    {
        return $this->runtime instanceof RuntimeConfiguration ? $this->drivers->withExtensions($this->runtime->drivers()) : $this->drivers;
    }

    private function locksOverridable(mixed $locks, string $path): bool
    {
        if (! is_array($locks)) {
            throw new InvalidConfiguration($path . ': an array is required.');
        }

        return ($locks['overridable'] ?? false) === true;
    }

    /** @return list<string> */
    private function approvalKeys(mixed $approvals, string $path): array
    {
        if (! is_array($approvals)) {
            throw new InvalidConfiguration($path . ': an array is required.');
        }

        $globs = $this->strings($approvals['keys'] ?? [], $path . '.keys');

        foreach ($globs as $glob) {
            if (preg_match('/^[a-z0-9_.*?\[\]]+$/', $glob) !== 1) {
                throw new InvalidConfiguration(sprintf('%s.keys: [%s] is not a key glob (a-z, 0-9, _, ., *, ?).', $path, $glob));
            }
        }

        return $globs;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $path): array
    {
        return ConfigValues::listOfStrings($value, $path);
    }
}
