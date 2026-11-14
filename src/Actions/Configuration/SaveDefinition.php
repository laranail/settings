<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Configuration;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingDefinition;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\DatabaseDefinitions;

/**
 * Save a definition into the definitions table from the attributes the configuration file takes.
 * A key already defined in code or configuration is refused.
 */
final readonly class SaveDefinition
{
    public function __construct(
        private DefinitionRegistry $registry,
        private DatabaseDefinitions $definitions,
        private ValueEncoder $encoder,
        private AccessGuard $access,
    ) {}

    /**
     * @param array<array-key, mixed> $attributes
     *
     * @throws InvalidDefinition
     * @throws InvalidConfiguration when the store has no definitions table
     */
    public function handle(Store $store, string $key, array $attributes, ?Actor $actor = null): Definition
    {
        $settingKey = new Key($key);
        $subject = new Subject($store->name, $settingKey);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        if ($this->registry->find($store->name, $settingKey) instanceof Definition) {
            throw new InvalidDefinition(sprintf('Setting [%s] in store [%s] is defined in code or configuration; change it there.', $settingKey, $store->name));
        }

        $definition = $this->registry->fromAttributes($key, $attributes, $store->name);
        $this->save($store, $definition);
        $this->definitions->forget($store);

        $this->access->auditor()->record(AuditAction::ManageDefinitions, AuditOutcome::Allowed, $subject, $actor, meta: ['operation' => 'save']);

        return $definition;
    }

    /**
     * Save the definition as a runtime row.
     *
     * @throws InvalidConfiguration when the store has no database layer
     * @throws InvalidDefinition when a system row already defines the key
     */
    private function save(Store $store, Definition $definition): void
    {
        $database = $store->database();

        if (! $database instanceof DatabaseDriver) {
            throw new InvalidConfiguration(sprintf('Store [%s] has no database layer, so it has no definitions table.', $store->name));
        }

        $default = $definition->hasDefault ? $this->encoder->encode($definition->key, $definition->default) : null;

        $database->attempt($store->name, static function () use ($database, $store, $definition, $default): void {
            $row = SettingDefinition::on($database->connectionName())->forStore($store->name)->where('key', $definition->key->value)->first();

            if ($row?->is_system === true) {
                throw new InvalidDefinition(sprintf('Setting [%s] in store [%s] is defined in code or configuration; change it there.', $definition->key, $store->name));
            }

            $row ??= SettingDefinition::on($database->connectionName())->make(['store' => $store->name->value, 'key' => $definition->key->value, 'is_system' => false]);
            $row->fillFromDefinition($definition, $default)->save();
        });
    }
}
