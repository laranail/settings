<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Models\SettingHistory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\SettingRolledBack;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;

/**
 * Write a setting's value from an earlier version as a new version, through the normal write
 * pipeline. Returns the new version.
 */
final readonly class RollbackSetting
{
    public function __construct(
        private SettingWriter $writer,
        private WriteGuard $guard,
        private ValuePipeline $pipeline,
        private Dispatcher $events,
    ) {}

    /** @throws HistoryUnavailable when the store keeps no history or has no such version */
    public function handle(SettingTarget $target, int $toVersion, ?Actor $actor = null): int
    {
        $version = 0;

        $this->guard->run(Ability::Rollback, $target, $actor, respectsLocks: true, write: function (bool $override) use ($target, $toVersion, $actor, &$version): array {
            $store = $target->store;
            $scope = $target->subject()->scope();
            $database = $store->database();
            if (! $database instanceof DatabaseDriver) {
                throw new HistoryUnavailable(sprintf('Store [%s] keeps no history, so [%s] cannot be rolled back.', $store->name, $target->key));
            }

            $entry = $database->attempt($store->name, static fn (): ?HistoryEntry => SettingHistory::entryAt($database->connectionName(), $store->name, $scope, $target->key, $target->locale, $toVersion));

            if (! $entry instanceof HistoryEntry || $entry->newValue === null) {
                throw new HistoryUnavailable(sprintf('Setting [%s] in store [%s] for scope [%s] has no value at version %d in its history.', $target->key, $store->name, $scope, $toVersion));
            }

            $value = $this->pipeline->fromRecord(new Record($scope, $target->key, $target->locale, $entry->newValue, $entry->newType, $entry->newEncrypted), $target->definition);
            $current = $store->writableLayer()->driver->load($store->name, [$scope])->find($scope, $target->key, $target->locale)->version ?? 0;

            $after = $this->writer->causedBy($actor, $target->reason)->rollback($store, $target->key, $target->locale, $target->owner, $target->definition, $value, $current, $override);
            $version = $after->version;
            $this->events->dispatch(new SettingRolledBack($store->name->value, $scope->value, $target->key->value, $target->locale->value, $toVersion, $after->version));

            return [$current > 0 ? $current : null, $after->version];
        });

        return $version;
    }
}
