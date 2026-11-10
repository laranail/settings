<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\DefinitionsSynced;
use Simtabi\Laranail\Settings\Models\SettingDefinition;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * Copy code and config definitions into the definitions table as system rows and remove system
 * rows whose definition is gone. Runtime rows are never touched; one sharing a key with a code
 * definition is reported as a conflict.
 *
 * @phpstan-type SyncReport array{synced: int, removed: int, conflicts: list<string>, skipped: list<string>}
 */
final readonly class SyncDefinitions
{
    public function __construct(
        private StoreRegistry $stores,
        private DefinitionRegistry $definitions,
        private ValueEncoder $encoder,
        private AccessGuard $access,
        private Dispatcher $events,
    ) {}

    /** @return SyncReport */
    public function handle(?Actor $actor = null): array
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        $report = ['synced' => 0, 'removed' => 0, 'conflicts' => [], 'skipped' => []];
        $byStore = [];

        foreach ($this->definitions->all() as $definition) {
            $byStore[$definition->store->value][] = $definition;
        }

        foreach ($this->stores->all() as $name => $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver) {
                $report['skipped'][] = $name;

                continue;
            }

            $result = $database->attempt($store->name, fn (): array => $database->transaction(fn (): array => $this->sync($database, $store->name, $byStore[$name] ?? []), $store->name));
            $report['synced'] += $result['synced'];
            $report['removed'] += $result['removed'];
            $report['conflicts'] = [...$report['conflicts'], ...$result['conflicts']];
        }

        $this->access->auditor()->record(AuditAction::ManageDefinitions, AuditOutcome::Allowed, $subject, $actor, meta: [
            'sync'      => true,
            'synced'    => $report['synced'],
            'removed'   => $report['removed'],
            'conflicts' => count($report['conflicts']),
        ]);
        $this->events->dispatch(new DefinitionsSynced($report['synced'], $report['removed'], count($report['conflicts']), count($report['skipped'])));

        return $report;
    }

    /**
     * Sync a store's code and config definitions as system rows, leaving runtime rows alone.
     *
     * @param list<Definition> $definitions
     *
     * @return array{synced: int, removed: int, conflicts: list<string>}
     */
    private function sync(DatabaseDriver $database, StoreName $store, array $definitions): array
    {
        $connection = $database->connectionName();
        $synced = 0;
        $conflicts = [];
        $keys = [];

        foreach ($definitions as $definition) {
            $keys[] = $definition->key->value;
            $row = SettingDefinition::on($connection)->forStore($store)->where('key', $definition->key->value)->first();

            if ($row !== null && ! $row->is_system) {
                $conflicts[] = $store->value . '.' . $definition->key->value;

                continue;
            }

            $row ??= SettingDefinition::on($connection)->make(['store' => $store->value, 'key' => $definition->key->value, 'is_system' => true]);
            $row->fillFromDefinition($definition, $this->encodedDefault($definition))->save();
            $synced++;
        }

        $removed = SettingDefinition::on($connection)->forStore($store)->where('is_system', true)->whereNotIn('key', $keys === [] ? [''] : $keys)->toBase()->delete();

        return ['synced' => $synced, 'removed' => $removed, 'conflicts' => $conflicts];
    }

    private function encodedDefault(Definition $definition): ?string
    {
        return $definition->hasDefault ? $this->encoder->encode($definition->key, $definition->default) : null;
    }
}
