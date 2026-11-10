<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configuration;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Data\ConfigurationEntry;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Actions\Configuration\ConfigurationActions;

/**
 * Persisted configuration (§16.11) edited as a handle's actor: stores and layers saved in the
 * `settings_stores` table, definitions in the definitions table of the handle's store. Each change
 * is validated like configuration, gated (`manage_stores`, `manage_definitions`) and audited.
 */
final readonly class PersistedConfigurationEditor
{
    public function __construct(
        private ConfigurationActions $actions,
        private Store $store,
        private ?Actor $actor = null,
    ) {}

    /** @return list<ConfigurationEntry> */
    public function entries(): array
    {
        return $this->actions->list->handle($this->actor);
    }

    /**
     * @param array<array-key, mixed> $config as `stores.<name>` would hold it
     *
     * @throws InvalidConfiguration
     */
    public function saveStore(string $name, array $config): void
    {
        $this->actions->save->handle(new ConfigurationEntry(ConfigurationEntry::STORE, $name, $config), $this->actor);
    }

    /**
     * @param array<array-key, mixed> $config as `layers.<name>` would hold it
     *
     * @throws InvalidConfiguration
     */
    public function saveLayer(string $name, array $config): void
    {
        $this->actions->save->handle(new ConfigurationEntry(ConfigurationEntry::LAYER, $name, $config), $this->actor);
    }

    public function deleteStore(string $name): bool
    {
        return $this->actions->delete->handle(new ConfigurationEntry(ConfigurationEntry::STORE, $name), $this->actor);
    }

    public function deleteLayer(string $name): bool
    {
        return $this->actions->delete->handle(new ConfigurationEntry(ConfigurationEntry::LAYER, $name), $this->actor);
    }

    /**
     * @param array<array-key, mixed> $attributes as `definitions.<key>` would hold them
     *
     * @throws InvalidDefinition
     */
    public function saveDefinition(string $key, array $attributes): Definition
    {
        return $this->actions->saveDefinition->handle($this->store, $key, $attributes, $this->actor);
    }

    public function deleteDefinition(string $key): bool
    {
        return $this->actions->deleteDefinition->handle($this->store, $key, $this->actor);
    }
}
