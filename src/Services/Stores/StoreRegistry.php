<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Stores;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Enums\IdType;
use Simtabi\Laranail\Settings\Stores\Layer;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\StoreMode;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Events\LayerSkipped;
use Simtabi\Laranail\Settings\Database\SchemaNames;
use Simtabi\Laranail\Settings\Drivers\CachingDriver;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Stores\InstallationEvidence;
use Simtabi\Laranail\Settings\Drivers\BootstrapGatedDriver;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;
use Simtabi\Laranail\Settings\Services\Configuration\PersistedConfiguration;

/**
 * Builds and validates every store from configuration on first use.
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
     * @param ?Dispatcher $events receives {@see LayerSkipped} when bootstrap mode skips a layer
     * @param list<string> $sources merge order of config, database and runtime entries; later wins
     * @param mixed $promote `laranail.settings.promote`: overrides every store's `promote` when set
     */
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly string $default,
        private readonly array $storeConfig,
        private readonly array $layerConfig,
        private readonly ?Driver $override = null,
        private readonly ?RuntimeConfiguration $runtime = null,
        private readonly ?InstallationEvidence $evidence = null,
        private readonly ?Dispatcher $events = null,
        private readonly ?SettingsCache $cache = null,
        private readonly ?PersistedConfiguration $persisted = null,
        private readonly array $sources = ['config', 'database', 'runtime'],
        private readonly mixed $promote = null,
    ) {}

    /** A copy whose every layer is replaced by one driver. */
    public function withEveryLayer(Driver $driver): self
    {
        return new self($this->drivers, $this->default, $this->storeConfig, $this->layerConfig, $driver, $this->runtime, $this->evidence, $this->events, $this->cache, $this->persisted, $this->sources, $this->promote);
    }

    /** @throws InvalidConfiguration for the first problem in any store or layer */
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

    /** The store holding a store's global values: itself, or a dedicated store's fallback. */
    public function globalOf(Store $store): Store
    {
        return $store->fallback instanceof StoreName ? $this->get($store->fallback) : $store;
    }

    /** The database layer holding a store's audit trail: its own, else the default store's, else null. */
    public function auditDatabase(?StoreName $store): ?DatabaseDriver
    {
        return ($store instanceof StoreName ? $this->get($store) : $this->default())->database() ?? $this->default()->database();
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
            $softDeletes = $config['soft_deletes'] ?? false;

            if (! is_bool($softDeletes)) {
                throw new InvalidConfiguration($path . '.soft_deletes: true or false.');
            }

            $promote = $this->promotion($storeName, $config['promote'] ?? 'manual', $path . '.promote');
            $owner = $this->dedicatedOwner($config, $storeName, $path);
            $options = ['table' => $table, 'soft_deletes' => $softDeletes, 'owner' => $owner];

            $built[$storeName->value] = new Store(
                name: $storeName,
                layers: array_map(fn (string $layer): Layer => $this->storeLayer($layer, $path, $storeName, $options, $bootstrap), $layerNames),
                owners: $owners,
                strict: ($config['strict'] ?? false) === true,
                inherits: ($config['inherits'] ?? false) === true,
                locksOverridable: $this->locksOverridable($config['locks'] ?? [], $path . '.locks'),
                bootstrap: $bootstrap,
                table: $table,
                mode: $owner === null ? StoreMode::Polymorphic : StoreMode::Dedicated,
                fallback: $owner === null ? null : new StoreName($owner['fallback']),
                approvalKeys: $this->approvalKeys($config['approvals'] ?? [], $path . '.approvals'),
                softDeletes: $softDeletes,
                autoPromote: $this->autoPromote($promote['mode'], $promote['modePath']),
                promotion: PromotionSelection::from($promote['include'], $promote['owners'], $promote['path']),
                ownerTable: $owner['table'] ?? null,
                ownerKey: $owner['key'] ?? 'id',
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
     * Build a layer for one store, with the bootstrap gate and settings cache where configured.
     *
     * The cache wraps the gate, so a skipped layer is never cached.
     */
    /** @param array<string, mixed> $options the store's table, soft deletes and dedicated owner, for a database layer */
    private function storeLayer(string $name, string $storePath, StoreName $store, array $options, bool $bootstrap): Layer
    {
        $layer = $this->gatedLayer($this->layer($name, $storePath, array_filter($options, static fn (mixed $value): bool => $value !== null)), $store, $bootstrap);

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

        return new Layer($name, new BootstrapGatedDriver($layer->driver, $store, $this->evidence ?? new InstallationEvidence(false), $this->events, $name), $layer->type);
    }

    /** @param array<string, mixed> $extra options the store adds */
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
     * @param Closure(): array<array-key, mixed> $persisted
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

    /**
     * The owner of a dedicated store, or null for a polymorphic one.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array{alias: string, table: string, key: string, type: ?string, fallback: string}|null
     */
    private function dedicatedOwner(array $config, StoreName $store, string $path): ?array
    {
        $mode = $config['mode'] ?? StoreMode::Polymorphic->value;
        $mode = is_string($mode) ? StoreMode::tryFrom($mode) : null;

        if (! $mode instanceof StoreMode) {
            throw new InvalidConfiguration($path . '.mode: use [polymorphic] or [dedicated].');
        }

        if ($mode === StoreMode::Polymorphic) {
            return null;
        }

        $owners = $config['owners'] ?? [];

        if (! is_array($owners) || count($owners) !== 1 || ! is_string($owners[array_key_first($owners)])) {
            throw new InvalidConfiguration($path . '.owners: a dedicated store holds exactly one owner alias.');
        }

        $owner = $config['owner'] ?? [];

        if (! is_array($owner) || ! is_string($owner['table'] ?? null)) {
            throw new InvalidConfiguration($path . '.owner.table: a dedicated store names the table its owners live in.');
        }

        SchemaNames::assertTable($owner['table'], $path . '.owner.table');
        $key = $owner['key'] ?? 'id';
        $type = $owner['type'] ?? null;

        if (! is_string($key) || preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $key) !== 1 || ($type !== null && (! is_string($type) || ! IdType::parse($type) instanceof IdType))) {
            throw new InvalidConfiguration($path . '.owner: `key` is a column name and `type` one of bigint, uuid, ulid.');
        }

        $fallback = $config['fallback'] ?? $this->default;

        try {
            $fallback = new StoreName(is_string($fallback) ? $fallback : '');
        } catch (InvalidIdentifier $exception) {
            throw new InvalidConfiguration($path . '.fallback: ' . $exception->getMessage(), 0, $exception);
        }

        if ($fallback->value === $store->value) {
            throw new InvalidConfiguration($path . '.fallback: a dedicated store has no global rows, so its fallback must be another store.');
        }

        return ['alias' => $owners[array_key_first($owners)], 'table' => $owner['table'], 'key' => $key, 'type' => $type, 'fallback' => $fallback->value];
    }

    /**
     * The one place a store's promotion options are resolved. Each option comes from the first
     * of: the fluent setters and runtime configuration for this request, the global `promote`
     * override (mode only), then the store's `promote` from persisted configuration or the file.
     * The string shorthand (`'auto'`) means every value.
     *
     * @return array{mode: mixed, include: mixed, owners: mixed, path: string, modePath: string}
     */
    private function promotion(StoreName $store, mixed $promote, string $path): array
    {
        if (is_array($promote)) {
            $unknown = array_diff(array_keys($promote), ['mode', 'include', 'owners']);

            if ($unknown !== []) {
                throw new InvalidConfiguration(sprintf('%s: unknown option [%s]; use mode, include and owners.', $path, implode(', ', $unknown)));
            }
        }

        $configured = is_array($promote)
            ? ['mode' => $promote['mode'] ?? 'manual', 'include' => $promote['include'] ?? ['*'], 'owners' => $promote['owners'] ?? null]
            : ['mode' => $promote, 'include' => ['*'], 'owners' => null];
        $modePath = is_array($promote) ? $path . '.mode' : $path;

        if ($this->promote !== null && $this->promote !== '') {
            [$configured['mode'], $modePath] = [$this->promote, 'laranail.settings.promote'];
        }

        $set = $this->runtime?->promotionOptions($store->value) ?? [];

        return [...$configured, ...$set, 'path' => $path, 'modePath' => $modePath];
    }

    private function autoPromote(mixed $mode, string $path): bool
    {
        return match ($mode) {
            'auto'   => true,
            'manual' => false,
            default  => throw new InvalidConfiguration($path . ': use [manual] or [auto].'),
        };
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
        $strings = is_array($value) ? array_values(array_filter($value, is_string(...))) : null;

        if ($strings === null || count($strings) !== count($value)) {
            throw new InvalidConfiguration($path . ': a list of strings is required.');
        }

        return $strings;
    }
}
