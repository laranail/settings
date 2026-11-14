<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Data\HistoryEntry;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Models\SettingHistory;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;

/**
 * Compare one setting at two versions. Sensitive values are decrypted only for an actor with
 * `view_sensitive`; anyone else gets a redacted diff saying only whether they differ.
 */
final readonly class DiffHistory
{
    public function __construct(
        private AccessGuard $access,
        private ValuePipeline $pipeline,
    ) {}

    /** @throws HistoryUnavailable when the store keeps no history or lacks either version */
    public function handle(SettingTarget $target, int $from, int $to, ?Actor $actor = null): HistoryDiff
    {
        $subject = $target->subject();
        $this->access->authorize($actor, Ability::ViewHistory, $subject);

        $store = $target->store;
        $scope = $subject->scope();
        $database = $store->database();
        if (! $database instanceof DatabaseDriver) {
            throw new HistoryUnavailable(sprintf('Store [%s] keeps no history.', $store->name));
        }

        $entries = [];

        foreach ([$from, $to] as $version) {
            $entries[] = $database->attempt($store->name, static fn (): ?HistoryEntry => SettingHistory::entryAt($database->connectionName(), $store->name, $scope, $target->key, $target->locale, $version))
                ?? throw new HistoryUnavailable(sprintf('Setting [%s] in store [%s] for scope [%s] has no version %d in its history.', $target->key, $store->name, $scope, $version));
        }

        [$older, $newer] = $entries;
        $protected = $subject->sensitive || $older->newEncrypted || $newer->newEncrypted;
        $redacted = $protected && $actor instanceof Actor && ! $this->access->allows($actor, Ability::ViewSensitive, $subject);

        return new HistoryDiff(
            store: $store->name->value,
            scope: $scope->value,
            key: $target->key->value,
            locale: $target->locale->value,
            fromVersion: $from,
            toVersion: $to,
            from: $redacted ? null : $this->value($older, $target),
            to: $redacted ? null : $this->value($newer, $target),
            fromFound: $older->newValue !== null,
            toFound: $newer->newValue !== null,
            changed: $older->newValue !== $newer->newValue || $older->newType !== $newer->newType,
            redacted: $redacted,
            encrypted: $protected,
        );
    }

    private function value(HistoryEntry $entry, SettingTarget $target): mixed
    {
        if ($entry->newValue === null) {
            return null;
        }

        return $this->pipeline->fromRecord(new Record($target->subject()->scope(), $target->key, $target->locale, $entry->newValue, $entry->newType, $entry->newEncrypted), $target->definition);
    }
}
