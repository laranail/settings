<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Stores;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Stores\Layer;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Support\Clock;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Logging\LogContext;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Events\SettingLocked;
use Simtabi\Laranail\Settings\Events\SettingSaving;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Enums\HistoryOperation;
use Simtabi\Laranail\Settings\Events\SettingUnlocked;
use Simtabi\Laranail\Settings\Events\SettingForgotten;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Events\SettingForgetting;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Logging\OperationalLogger;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;
use Simtabi\Laranail\Settings\Services\Database\HistoryRepository;

/**
 * Every write path: set, forget, lock and unlock.
 *
 * Checks the definition, owner, lock, precedence, locale and value before writing to the first
 * writable layer. Where the schema has a history table, each change appends a history entry in
 * the same transaction.
 */
final readonly class SettingWriter
{
    /** @param bool $retryOnConflict retry a plain set once after a concurrent change */
    public function __construct(
        private ValuePipeline $pipeline,
        private Dispatcher $events,
        private Clock $clock,
        private OperationalLogger $log,
        #[Config('laranail.settings.writes.retry_on_conflict', true)]
        private bool $retryOnConflict = true,
        private ?Actor $causer = null,
        private ?string $reason = null,
    ) {}

    /** A copy whose history entries name this causer and reason; null is the system. */
    public function causedBy(?Actor $causer, ?string $reason = null): self
    {
        return new self($this->pipeline, $this->events, $this->clock, $this->log, $this->retryOnConflict, $causer, $reason);
    }

    /**
     * Write a recovered value as a new version, recorded as a `rollback`.
     *
     * @throws ConcurrentModification
     */
    public function rollback(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, int $expectedVersion, bool $overrideLock = false): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, $overrideLock, $expectedVersion, HistoryOperation::Rollback);
    }

    /** Store a value and return the stored record. */
    public function set(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, overrideLock: false);
    }

    /**
     * Store a value only if the row is still at `$expectedVersion` (0 for no row); never retried.
     *
     * @throws ConcurrentModification
     */
    public function setExpecting(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, int $expectedVersion, bool $overrideLock = false): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, $overrideLock, $expectedVersion);
    }

    /** Store a value over a row lock, for a caller already allowed to override it. */
    public function setOverridingLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, overrideLock: true);
    }

    /** Remove this scope's own value and return the removed record, if any. */
    public function forget(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition): ?Record
    {
        return $this->remove($store, $key, $locale, $owner, $definition, overrideLock: false);
    }

    /** {@see forget()} over a row lock; the same precondition as {@see setOverridingLock()}. */
    public function forgetOverridingLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition): ?Record
    {
        return $this->remove($store, $key, $locale, $owner, $definition, overrideLock: true);
    }

    /** Whether this scope's row in the writable layer is locked. */
    public function isLocked(Store $store, Key $key, Locale $locale, ?OwnerRef $owner): bool
    {
        $scope = $this->scope($owner);

        return $store->writableLayer()->driver->load($store->name, [$scope])->find($scope, $key, $locale)?->locked === true;
    }

    /** Lock one row against writes, creating a value-less row when none exists. */
    public function lock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition = null): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: true, definition: $definition);
    }

    public function unlock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition = null): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: false, definition: $definition);
    }

    private function write(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, bool $overrideLock, ?int $expectedVersion = null, HistoryOperation $operation = HistoryOperation::Set): Record
    {
        $retries = $expectedVersion === null && $this->retryOnConflict ? 1 : 0;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->writeOnce($store, $key, $locale, $owner, $definition, $value, $overrideLock, $expectedVersion, $operation, relocked: $attempt > 0);
            } catch (ConcurrentModification $conflict) {
                if ($attempt >= $retries) {
                    throw $conflict;
                }

                $scope = $this->scope($owner);
                $this->log->info(sprintf('laranail/settings retried a write of [%s] in store [%s] for scope [%s] after a concurrent change.', $key, $store->name, $scope), LogContext::of(
                    $store->name->value,
                    $key->value,
                    $scope->value,
                    $this->causer,
                ));
            }
        }
    }

    private function writeOnce(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, bool $overrideLock, ?int $expectedVersion, HistoryOperation $operation, bool $relocked): Record
    {
        $this->guard($store, $key, $locale, $owner, $definition, $overrideLock);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $database = $this->database($layer);
        $existing = $relocked && $database instanceof DatabaseDriver
            ? $database->lockedFind($store->name, $scope, $key, $locale)
            : $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale);

        if ($expectedVersion !== null && ($existing->version ?? 0) !== $expectedVersion) {
            throw new ConcurrentModification(sprintf('Setting [%s] in store [%s] for scope [%s] is at version %d, not the expected %d; re-read and retry.', $key, $store->name, $scope, $existing->version ?? 0, $expectedVersion));
        }

        $version = $this->baseVersion($layer, $store, $scope, $key, $locale, $existing) + 1;

        $record = new Record(
            scope: $scope,
            key: $key,
            locale: $locale,
            payload: $this->pipeline->toPayload($key, $value, $definition),
            type: $definition?->type,
            encrypted: $definition instanceof Definition && $definition->encrypted,
            locked: $existing->locked ?? false,
            version: $version,
            writtenAt: $this->clock->now(),
            group: $definition?->group(),
            category: $definition?->category,
            public: $definition instanceof Definition && $definition->public,
        );

        $this->events->dispatch(new SettingSaving($store->name->value, $scope->value, $key->value, $locale->value, $version));
        $this->persist($store, $layer, $operation, $existing, $record);
        $this->events->dispatch(new SettingSaved($store->name->value, $scope->value, $key->value, $locale->value, $version));

        return $record;
    }

    /** The version a write continues: the live row's, the trashed row's, the history's, or 0. */
    private function baseVersion(Layer $layer, Store $store, Scope $scope, Key $key, Locale $locale, ?Record $existing): int
    {
        if ($existing instanceof Record) {
            return $existing->version;
        }

        $trashed = $this->database($layer)?->trashedVersion($store->name, $scope, $key, $locale) ?? 0;

        return max($trashed, $this->history($layer)?->latestVersion($store->name, $scope, $key, $locale) ?? 0);
    }

    /** Store a record and its history entry in one transaction. */
    private function persist(Store $store, Layer $layer, HistoryOperation $operation, ?Record $before, Record $after): void
    {
        $history = $this->history($layer);

        if (! $history instanceof HistoryRepository) {
            $layer->driver->put($store->name, $after);

            return;
        }

        $history->database()->transaction(function () use ($store, $layer, $history, $operation, $before, $after): void {
            $layer->driver->put($store->name, $after);
            $history->record($store->name, $operation, $before, $after, $after->version, $history->database()->reference($store->name, $after->scope, $after->key, $after->locale), $this->causer, $this->reason, $this->clock->now());
        }, $store->name);
    }

    /** The history table of the layer's database, when the recorded schema has one. */
    private function history(Layer $layer): ?HistoryRepository
    {
        $database = $this->database($layer);

        return $database instanceof DatabaseDriver && $database->shape()->historyTable ? new HistoryRepository($database) : null;
    }

    private function database(Layer $layer): ?DatabaseDriver
    {
        return $layer->driver instanceof DatabaseBacked && $layer->driver->isActive() ? $layer->driver->database() : null;
    }

    private function remove(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, bool $overrideLock): ?Record
    {
        $this->guard($store, $key, $locale, $owner, $definition, $overrideLock);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale);

        if (! $existing instanceof Record) {
            return null;
        }

        $this->events->dispatch(new SettingForgetting($store->name->value, $scope->value, $key->value, $locale->value, $existing->version));
        $history = $this->history($layer);

        if ($history instanceof HistoryRepository) {
            // A tombstone: the version that was removed, no new value. The next write continues after it.
            $history->database()->transaction(function () use ($store, $layer, $history, $scope, $key, $locale, $existing): void {
                $reference = $history->database()->reference($store->name, $scope, $key, $locale);
                $layer->driver->forget($store->name, $scope, $key, $locale);
                $history->record($store->name, HistoryOperation::Forget, $existing, null, $existing->version, $reference, $this->causer, $this->reason, $this->clock->now());
            }, $store->name);
        } else {
            $layer->driver->forget($store->name, $scope, $key, $locale);
        }

        $this->events->dispatch(new SettingForgotten($store->name->value, $scope->value, $key->value, $locale->value, $existing->version));

        return $existing;
    }

    private function storeLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, bool $locked, ?Definition $definition): Record
    {
        $this->ownerAllowed($store, $owner, null);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $found = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale);
        $existing = $found ?? new Record($scope, $key, $locale, null, version: $this->baseVersion($layer, $store, $scope, $key, $locale, null));

        $record = new Record(
            $existing->scope,
            $existing->key,
            $existing->locale,
            $existing->payload,
            $existing->type,
            $existing->encrypted,
            $locked,
            $existing->version + 1,
            $this->clock->now(),
            $definition?->group(),
            $definition?->category,
            $definition instanceof Definition && $definition->public,
        );

        $this->persist($store, $layer, $locked ? HistoryOperation::Lock : HistoryOperation::Unlock, $found, $record);

        $event = $locked ? SettingLocked::class : SettingUnlocked::class;
        $this->events->dispatch(new $event($store->name->value, $scope->value, $key->value, $locale->value, $record->version));

        return $record;
    }

    private function guard(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, bool $overrideLock): void
    {
        if (! $definition instanceof Definition && $store->strict) {
            throw new UnknownSetting(sprintf('Store [%s] is strict and has no definition for [%s].', $store->name, $key));
        }

        $this->ownerAllowed($store, $owner, $definition);

        if ($definition instanceof Definition && $definition->locked) {
            throw new LockedSetting(sprintf('Setting [%s] in store [%s] is locked by its definition.', $key, $store->name));
        }

        if ($definition instanceof Definition && ! $definition->precedence->allowsWrites()) {
            throw new LockedSetting(sprintf('Setting [%s] in store [%s] takes its value from [%s] and cannot be written.', $key, $store->name, $definition->precedence->value));
        }

        $scope = $this->scope($owner);

        if (! $overrideLock && $this->isLocked($store, $key, $locale, $owner)) {
            throw new LockedSetting(sprintf('Setting [%s] in store [%s] is locked for scope [%s].', $key, $store->name, $scope));
        }

        if ($locale->isLocalised() && $definition instanceof Definition && ! $definition->localised) {
            throw new InvalidSettingValue(sprintf('Setting [%s] is not localised; write it without a locale.', $key));
        }
    }

    private function ownerAllowed(Store $store, ?OwnerRef $owner, ?Definition $definition): void
    {
        if (! $owner instanceof OwnerRef) {
            if ($store->isDedicated()) {
                throw new OwnerNotAllowed(sprintf('Store [%s] is dedicated to its owners and holds no global values; write those to store [%s].', $store->name, $store->fallback));
            }

            return;
        }

        if (! $store->accepts($owner->alias)) {
            throw new OwnerNotAllowed(sprintf('Store [%s] does not accept owner alias [%s].', $store->name, $owner->alias));
        }

        if ($definition instanceof Definition && ! $definition->allowsOwner($owner->alias)) {
            throw new OwnerNotAllowed(sprintf('Setting [%s] cannot be overridden by [%s].', $definition->key, $owner->alias));
        }
    }

    private function scope(?OwnerRef $owner): Scope
    {
        return Scope::of($owner);
    }
}
