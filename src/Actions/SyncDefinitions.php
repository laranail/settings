<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Illuminate\Contracts\Cache\Lock;
use Simtabi\Laranail\Settings\Data\Actor;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\Subject;
use Illuminate\Contracts\Cache\LockProvider;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Models\SettingCategory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
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
 * One run at a time, under a cache lock: a run that cannot take it within `$wait` seconds does
 * nothing and reports `busy`, rather than failing on the rows the other run is inserting.
 *
 * @phpstan-type SyncReport array{synced: int, removed: int, conflicts: list<string>, skipped: list<string>, busy: bool}
 */
final readonly class SyncDefinitions
{
    public function __construct(
        private StoreRegistry $stores,
        private DefinitionRegistry $definitions,
        private ValueEncoder $encoder,
        private AccessGuard $access,
        private Dispatcher $events,
        private ?CacheFactory $caches = null,
        private int $wait = 5,
    ) {}

    /** @return SyncReport */
    public function handle(?Actor $actor = null): array
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        $store = $this->caches?->store()->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('laranail-settings:sync-definitions', 300) : null;

        if ($lock !== null && ! $this->acquire($lock)) {
            return ['synced' => 0, 'removed' => 0, 'conflicts' => [], 'skipped' => [], 'busy' => true];
        }

        try {
            return $this->syncAll($subject, $actor);
        } finally {
            $lock?->release();
        }
    }

    /** @return SyncReport */
    private function syncAll(Subject $subject, ?Actor $actor): array
    {
        $report = ['synced' => 0, 'removed' => 0, 'conflicts' => [], 'skipped' => [], 'busy' => false];
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
        // Every row of the store in one query, not one per definition.
        $rows = SettingDefinition::on($connection)->forStore($store)->get()->keyBy('key');
        $categories = SettingCategory::on($connection)->central()->forStore($store)->pluck('id', 'slug')->all();

        foreach ($definitions as $definition) {
            $keys[] = $definition->key->value;
            $row = $rows->get($definition->key->value);

            if ($row !== null && ! $row->is_system) {
                $conflicts[] = $store->value . '.' . $definition->key->value;

                continue;
            }

            $row ??= SettingDefinition::on($connection)->make(['store' => $store->value, 'key' => $definition->key->value, 'is_system' => true]);
            $row->fillFromDefinition($definition, $this->encodedDefault($definition), $categories)->save();
            $synced++;
        }

        $removed = SettingDefinition::on($connection)->forStore($store)->where('is_system', true)->whereNotIn('key', $keys === [] ? [''] : $keys)->toBase()->delete();

        return ['synced' => $synced, 'removed' => $removed, 'conflicts' => $conflicts];
    }

    /** Take the lock, waiting up to `$wait` seconds for a run that holds it. */
    private function acquire(Lock $lock): bool
    {
        try {
            return $this->wait > 0 ? $lock->block($this->wait) === true : $lock->get() === true;
        } catch (LockTimeoutException) {
            return false;
        }
    }

    private function encodedDefault(Definition $definition): ?string
    {
        return $definition->hasDefault ? $this->encoder->encode($definition->key, $definition->default) : null;
    }
}
