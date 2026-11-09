<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Driver;
use Simtabi\Laranail\Settings\Data\LayerConfig;
use Simtabi\Laranail\Settings\Enums\DriverType;
use Simtabi\Laranail\Settings\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\UnknownStore;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Builds and validates every store from configuration on first use. Configuration errors are
 * raised here, naming the config path, rather than surfacing later as a wrong value.
 */
final class StoreRegistry
{
    /** @var array<string, Store>|null */
    private ?array $stores = null;

    /**
     * @param array<array-key, mixed> $storeConfig `laranail.settings.stores`
     * @param array<array-key, mixed> $layerConfig `laranail.settings.layers`
     */
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly string $default,
        private readonly array $storeConfig,
        private readonly array $layerConfig,
        private readonly ?Driver $override = null,
    ) {}

    /** A copy whose every layer is replaced by one driver (used by the fake). */
    public function withEveryLayer(Driver $driver): self
    {
        return new self($this->drivers, $this->default, $this->storeConfig, $this->layerConfig, $driver);
    }

    public function default(): Store
    {
        return $this->get(new StoreName($this->default));
    }

    public function get(StoreName $name): Store
    {
        return $this->all()[$name->value] ?? throw new UnknownStore(sprintf('Store [%s] is not configured in laranail.settings.stores.', $name));
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
        return $this->stores ??= $this->build();
    }

    /** @return array<string, Store> */
    private function build(): array
    {
        $built = [];
        $claimed = [];

        foreach ($this->storeConfig as $name => $config) {
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

            $built[$storeName->value] = new Store(
                name: $storeName,
                layers: array_map(fn (string $layer): Layer => $this->layer($layer, $path), $layerNames),
                owners: $owners,
                strict: ($config['strict'] ?? false) === true,
                inherits: ($config['inherits'] ?? false) === true,
            );
        }

        if (! isset($built[$this->default])) {
            throw new InvalidConfiguration(sprintf('laranail.settings.default: store [%s] is not configured.', $this->default));
        }

        return $built;
    }

    private function layer(string $name, string $storePath): Layer
    {
        $config = $this->layerConfig[$name] ?? null;

        if (! is_array($config)) {
            throw new InvalidConfiguration(sprintf('%s.layers: layer [%s] is not defined in laranail.settings.layers.', $storePath, $name));
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver)) {
            throw new InvalidConfiguration(sprintf('laranail.settings.layers.%s.driver: a driver name is required.', $name));
        }

        $options = array_filter($config, is_string(...), ARRAY_FILTER_USE_KEY);
        $layer = new LayerConfig($name, $driver, ($config['writable'] ?? true) === true, $options);

        return new Layer($name, $this->override ?? $this->drivers->make($layer), $this->override instanceof Driver ? DriverType::Array : DriverType::tryFrom($driver));
    }

    /** @return list<string> */
    private function strings(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw new InvalidConfiguration($path . ': a list of strings is required.');
        }

        $strings = array_values(array_filter($value, is_string(...)));

        if (count($strings) !== count($value)) {
            throw new InvalidConfiguration($path . ': a list of strings is required.');
        }

        return $strings;
    }
}
