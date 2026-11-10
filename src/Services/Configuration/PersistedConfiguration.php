<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Configuration;

use Closure;
use Exception;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Simtabi\Laranail\Settings\Support\Json;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Database\SchemaShape;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * Store and layer configuration saved through the API (§16.11, `configuration.persisted`), kept in
 * the `settings_stores` table on the default store's connection. Read on first use, never at boot,
 * once per request or job (bound `scoped`); an entry that fails validation is logged and skipped,
 * so a bad row can never stop the application from booting. Writes are validated the same way
 * first and refused with InvalidConfiguration.
 *
 * A persisted store may not change the layers of a store the configuration file defines: moving a
 * store's data between drivers is `promote`'s job, not an edit's.
 */
final class PersistedConfiguration
{
    /** @var array{stores: array<string, array<array-key, mixed>>, layers: array<string, array<array-key, mixed>>}|null */
    private ?array $loaded = null;

    /** @var list<string> */
    private array $problems = [];

    private int $generation = 0;

    /**
     * @param Closure(): Connection $connection the default store's database connection
     * @param Closure(): SchemaShape $shape the recorded schema shape
     * @param Closure(array<array-key, mixed>, array<array-key, mixed>): void $validate builds stores and layers, raising InvalidConfiguration
     * @param array<array-key, mixed> $configStores `laranail.settings.stores`, which a persisted entry may not re-layer
     * @param (Closure(string): void)|null $log told about each skipped entry
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly Closure $connection,
        private readonly Closure $shape,
        private readonly Closure $validate,
        private readonly array $configStores = [],
        private readonly ?Closure $log = null,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled && ($this->shape)()->storesTable;
    }

    /** Changes whenever an entry is saved or deleted, so registries rebuild. */
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

    /** @return list<string> why entries were skipped on the last read (doctor) */
    public function problems(): array
    {
        $this->load();

        return $this->problems;
    }

    /**
     * Save a store entry, as the configuration file would hold it.
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

    /** Remove a persisted entry; returns whether there was one. */
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

        ($this->validate)($stores, $layers);
    }

    private function skip(string $problem): void
    {
        $this->problems[] = $problem;

        if ($this->log instanceof Closure) {
            ($this->log)('laranail/settings skipped a persisted configuration entry: ' . $problem);
        }
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
            $connection = ($this->connection)();
        } catch (Exception $exception) {
            throw new DriverFailure('The persisted configuration connection is unavailable.', 0, $exception);
        }

        return $connection->table(($this->shape)()->names->stores());
    }
}
