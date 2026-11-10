<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Configuration;

use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Logging\LogContext;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Drivers\DriverFactory;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Database\SchemaShapeRepository;

/**
 * Store and layer configuration saved in the `settings_stores` table.
 *
 * Read on first use, never at boot. An invalid entry is logged and skipped so a bad row cannot
 * stop the application from booting.
 */
#[Scoped]
final class PersistedConfiguration
{
    /** @var array{stores: array<string, array<array-key, mixed>>, layers: array<string, array<array-key, mixed>>}|null */
    private ?array $loaded = null;

    /** @var list<string> */
    private array $problems = [];

    private int $generation = 0;

    /**
     * @param DriverFactory $drivers validates an entry by building the stores it would produce
     * @param mixed $enabled `configuration.persisted`
     * @param array<array-key, mixed> $configStores `laranail.settings.stores`
     * @param array<array-key, mixed> $configLayers `laranail.settings.layers`
     */
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly SchemaShapeRepository $shapes,
        private readonly DriverFactory $drivers,
        private readonly OperationalLogger $log,
        #[Config('laranail.settings.configuration.persisted', false)]
        private readonly mixed $enabled = false,
        #[Config('laranail.settings.stores', [])]
        private readonly array $configStores = [],
        #[Config('laranail.settings.layers', [])]
        private readonly array $configLayers = [],
        #[Config('laranail.settings.default', 'settings')]
        private readonly string $default = 'settings',
    ) {}

    public function isEnabled(): bool
    {
        return filter_var($this->enabled, FILTER_VALIDATE_BOOL) && $this->shape()->storesTable;
    }

    /** Changes whenever an entry is saved or deleted so registries rebuild. */
    public function generation(): int
    {
        return $this->generation;
    }

    /** @return array<string, array<array-key, mixed>> valid persisted stores, by name */
    public function stores(): array
    {
        return $this->load()['stores'];
    }

    /** @return array<string, array<array-key, mixed>> valid persisted layers, by name */
    public function layers(): array
    {
        return $this->load()['layers'];
    }

    /** @return list<string> why entries were skipped on the last read */
    public function problems(): array
    {
        $this->load();

        return $this->problems;
    }

    /**
     * Save a store entry in the configuration file's shape.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidConfiguration
     */
    public function saveStore(string $name, array $config): void
    {
        $this->save('store', $name, $config);
    }

    /**
     * Save a layer entry.
     *
     * @param array<array-key, mixed> $config
     *
     * @throws InvalidConfiguration
     */
    public function saveLayer(string $name, array $config): void
    {
        $this->save('layer', $name, $config);
    }

    /** Remove a persisted entry and return whether there was one. */
    public function delete(string $kind, string $name): bool
    {
        $this->assertEnabled();
        $deleted = $this->table()->where('kind', $kind)->where('name', $name)->delete() > 0;
        $this->changed();

        return $deleted;
    }

    /** @param array<array-key, mixed> $config */
    private function save(string $kind, string $name, array $config): void
    {
        $this->assertEnabled();

        try {
            new StoreName($name);
        } catch (InvalidIdentifier $exception) {
            throw new InvalidConfiguration(sprintf('Persisted %s [%s]: %s', $kind, $name, $exception->getMessage()), 0, $exception);
        }

        $loaded = $this->load();
        $stores = $loaded['stores'];
        $layers = $loaded['layers'];
        $kind === 'store' ? $stores[$name] = $config : $layers[$name] = $config;

        $this->check($kind, $name, $config, $stores, $layers);

        $now = CarbonImmutable::now();
        $json = Json::encode($config, 'A persisted ' . $kind);

        if ($this->table()->where('kind', $kind)->where('name', $name)->exists()) {
            $this->table()->where('kind', $kind)->where('name', $name)->update(['config' => $json, 'updated_at' => $now]);
        } else {
            $this->table()->insert(['id' => (string) Str::ulid(), 'kind' => $kind, 'name' => $name, 'config' => $json, 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->changed();
    }

    /**
     * @return array{stores: array<string, array<array-key, mixed>>, layers: array<string, array<array-key, mixed>>}
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $this->problems = [];
        $accepted = ['stores' => [], 'layers' => []];

        if (! $this->isEnabled()) {
            return $this->loaded = $accepted;
        }

        try {
            $rows = $this->table()->orderBy('kind')->orderBy('name')->get(['kind', 'name', 'config'])->all();
        } catch (Exception $exception) {
            $this->skip(sprintf('the persisted configuration table could not be read (%s)', $exception->getMessage()));

            return $this->loaded = $accepted;
        }

        // Layers first: a store entry may use a persisted layer.
        usort($rows, static fn (object $a, object $b): int => ((array) $a)['kind'] === 'layer' ? -1 : (((array) $b)['kind'] === 'layer' ? 1 : 0));

        foreach ($rows as $row) {
            $row = (array) $row;
            $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
            $name = is_string($row['name'] ?? null) ? $row['name'] : '';
            $config = is_string($row['config'] ?? null) ? json_decode($row['config'], true) : null;

            if (! in_array($kind, ['store', 'layer'], true) || ! is_array($config)) {
                $this->skip(sprintf('%s [%s] is not a configuration entry', $kind, $name));

                continue;
            }

            $stores = $accepted['stores'];
            $layers = $accepted['layers'];
            $kind === 'store' ? $stores[$name] = $config : $layers[$name] = $config;

            try {
                $this->check($kind, $name, $config, $stores, $layers);
            } catch (SettingsException $exception) {
                $this->skip(sprintf('%s [%s]: %s', $kind, $name, $exception->getMessage()));

                continue;
            }

            $accepted = ['stores' => $stores, 'layers' => $layers];
        }

        return $this->loaded = $accepted;
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<string, array<array-key, mixed>> $stores
     * @param array<string, array<array-key, mixed>> $layers
     *
     * @throws InvalidConfiguration
     */
    private function check(string $kind, string $name, array $config, array $stores, array $layers): void
    {
        $configured = $this->configStores[$name] ?? null;

        if ($kind === 'store' && is_array($configured) && ($configured['layers'] ?? null) !== ($config['layers'] ?? null)) {
            throw new InvalidConfiguration(sprintf('Persisted store [%s] would change the layers the configuration file gives it; change them there and promote the data.', $name));
        }

        new StoreRegistry($this->drivers, $this->default, array_replace($this->configStores, $stores), array_replace($this->configLayers, $layers))->validate();
    }

    private function skip(string $problem): void
    {
        $this->problems[] = $problem;

        $this->log->warning('laranail/settings skipped a persisted configuration entry: ' . $problem, LogContext::of());
    }

    private function changed(): void
    {
        $this->loaded = null;
        $this->generation++;
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new InvalidConfiguration('laranail.settings.configuration.persisted: persisted configuration is off (or its table was never migrated); turn it on and run the migration first.');
        }
    }

    private function table(): Builder
    {
        try {
            $connection = $this->connection();
        } catch (Exception $exception) {
            throw new DriverFailure('The persisted configuration connection is unavailable.', 0, $exception);
        }

        return $connection->table($this->shape()->names->stores());
    }

    private function shape(): SchemaShape
    {
        return $this->shapes->current();
    }

    /** The connection of the default store's first database layer. */
    private function connection(): Connection
    {
        $default = $this->configStores[$this->default] ?? null;
        $connection = null;

        foreach ((array) (is_array($default) ? ($default['layers'] ?? []) : []) as $layer) {
            $config = is_string($layer) ? ($this->configLayers[$layer] ?? null) : null;

            if (is_array($config) && ($config['driver'] ?? null) === 'database') {
                $connection = is_string($config['connection'] ?? null) ? $config['connection'] : null;

                break;
            }
        }

        $resolved = $this->db->connection($connection);

        return $resolved instanceof Connection ? $resolved : throw new InvalidConfiguration('laranail.settings.configuration: the default store\'s database connection is not a database connection.');
    }
}
