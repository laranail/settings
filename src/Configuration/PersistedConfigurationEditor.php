<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configuration;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteDefinition;
use Simtabi\Laranail\Settings\Actions\Configuration\SaveConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\DeleteConfigurationEntry;
use Simtabi\Laranail\Settings\Actions\Configuration\ListConfigurationEntries;

/**
 * Edits the stores, layers and definitions kept in the database, as a handle's actor. Each change
 * is validated, authorized and audited.
 */
final readonly class PersistedConfigurationEditor
{
    /** Without {@see in()}, edits the default store as trusted code. */
    public function __construct(
        private ListConfigurationEntries $list,
        private SaveConfigurationEntry $save,
        private DeleteConfigurationEntry $delete,
        private SaveDefinition $saveDefinition,
        private DeleteDefinition $deleteDefinition,
        private StoreRegistry $stores,
        private ?Store $store = null,
        private ?Actor $actor = null,
    ) {}

    /** This configuration, with the store's definitions, as this actor. */
    public function in(Store $store, ?Actor $actor = null): self
    {
        return new self($this->list, $this->save, $this->delete, $this->saveDefinition, $this->deleteDefinition, $this->stores, $store, $actor);
    }

    /** @return list<ConfigurationEntry> */
    public function entries(): array
    {
        return $this->list->handle($this->actor);
    }

    /**
     * @param array<array-key, mixed> $config as `stores.<name>` would hold it
     *
     * @throws InvalidConfiguration
     */
    public function saveStore(string $name, array $config): void
    {
        $this->save->handle(new ConfigurationEntry(ConfigurationEntry::STORE, $name, $config), $this->actor);
    }

    /**
     * @param array<array-key, mixed> $config as `layers.<name>` would hold it
     *
     * @throws InvalidConfiguration
     */
    public function saveLayer(string $name, array $config): void
    {
        $this->save->handle(new ConfigurationEntry(ConfigurationEntry::LAYER, $name, $config), $this->actor);
    }

    public function deleteStore(string $name): bool
    {
        return $this->delete->handle(new ConfigurationEntry(ConfigurationEntry::STORE, $name), $this->actor);
    }

    public function deleteLayer(string $name): bool
    {
        return $this->delete->handle(new ConfigurationEntry(ConfigurationEntry::LAYER, $name), $this->actor);
    }

    /**
     * @param array<array-key, mixed> $attributes as `definitions.<key>` would hold them
     *
     * @throws InvalidDefinition
     */
    public function saveDefinition(string $key, array $attributes): Definition
    {
        return $this->saveDefinition->handle($this->store(), $key, $attributes, $this->actor);
    }

    public function deleteDefinition(string $key): bool
    {
        return $this->deleteDefinition->handle($this->store(), $key, $this->actor);
    }

    private function store(): Store
    {
        return $this->store ?? $this->stores->default();
    }
}
