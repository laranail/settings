<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Stores;

use Closure;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Events\SettingSaved;
use Simtabi\Laranail\Settings\Events\SettingSaving;
use Simtabi\Laranail\Settings\Support\ValuePipeline;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Events\SettingForgotten;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Events\SettingForgetting;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * Every write path: set, forget, lock, unlock. Checks run before anything is stored, in this
 * order: definition (strict stores) → owner allowed → lock and precedence → locale → value
 * pipeline → the first writable layer, with the version continued from the existing record
 * (or from a trashed row, in a store with soft deletes, so the write restores it).
 *
 * A plain set that loses a race (another writer changed the row between the read and the
 * write) is retried once when `writes.retry_on_conflict` is on, re-reading the row under a row
 * lock; a write that names the version it expects is never retried.
 */
final readonly class SettingWriter
{
    /**
     * @param Closure(): CarbonImmutable $clock
     * @param (Closure(Store, Key, Scope, ConcurrentModification): void)|null $onRetry told before a retry (logging)
     */
    public function __construct(
        private ValuePipeline $pipeline,
        private Dispatcher $events,
        private Closure $clock,
        private bool $retryOnConflict = false,
        private ?Closure $onRetry = null,
    ) {}

    /** Store a value and return the stored record. A locked row refuses it. */
    public function set(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, overrideLock: false);
    }

    /**
     * Store a value only if this scope's row is still at `$expectedVersion` (0: no row yet).
     * Anything else raises {@see ConcurrentModification}; it is never retried.
     *
     * @throws ConcurrentModification
     */
    public function setExpecting(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, int $expectedVersion, bool $overrideLock = false): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, $overrideLock, $expectedVersion);
    }

    /**
     * Store a value over a row lock. Only for a caller that has already established the right
     * to (an actor holding `override_lock` on a store whose locks are overridable); the lock
     * stays in place. Definition locks and precedence still refuse.
     */
    public function setOverridingLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, overrideLock: true);
    }

    /** Remove this scope's own value; returns the removed record, or null when there was none. */
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

    /**
     * Lock one row against writes; a lock with no value yet is kept as a value-less row. The
     * definition, when given, supplies the row's group, category and visibility.
     */
    public function lock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition = null): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: true, definition: $definition);
    }

    public function unlock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition = null): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: false, definition: $definition);
    }

    private function write(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, bool $overrideLock, ?int $expectedVersion = null): Record
    {
        $retries = $expectedVersion === null && $this->retryOnConflict ? 1 : 0;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->writeOnce($store, $key, $locale, $owner, $definition, $value, $overrideLock, $expectedVersion, relocked: $attempt > 0);
            } catch (ConcurrentModification $conflict) {
                if ($attempt >= $retries) {
                    throw $conflict;
                }

                if ($this->onRetry instanceof Closure) {
                    ($this->onRetry)($store, $key, $this->scope($owner), $conflict);
                }
            }
        }
    }

    private function writeOnce(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, bool $overrideLock, ?int $expectedVersion, bool $relocked): Record
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
            writtenAt: ($this->clock)(),
            group: $definition?->group(),
            category: $definition?->category,
            public: $definition instanceof Definition && $definition->public,
        );

        $this->events->dispatch(new SettingSaving($store->name->value, $scope->value, $key->value, $locale->value, $version));
        $layer->driver->put($store->name, $record);
        $this->events->dispatch(new SettingSaved($store->name->value, $scope->value, $key->value, $locale->value, $version));

        return $record;
    }

    /**
     * The version a write continues: the live row's, else (soft deletes) the trashed row's, so
     * the write restores it instead of colliding with it, else 0.
     */
    private function baseVersion(Layer $layer, Store $store, Scope $scope, Key $key, Locale $locale, ?Record $existing): int
    {
        if ($existing instanceof Record) {
            return $existing->version;
        }

        return $this->database($layer)?->trashedVersion($store->name, $scope, $key, $locale) ?? 0;
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
        $layer->driver->forget($store->name, $scope, $key, $locale);
        $this->events->dispatch(new SettingForgotten($store->name->value, $scope->value, $key->value, $locale->value, $existing->version));

        return $existing;
    }

    private function storeLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, bool $locked, ?Definition $definition): Record
    {
        $this->ownerAllowed($store, $owner, null);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale)
            ?? new Record($scope, $key, $locale, null, version: $this->baseVersion($layer, $store, $scope, $key, $locale, null));

        $record = new Record(
            $existing->scope,
            $existing->key,
            $existing->locale,
            $existing->payload,
            $existing->type,
            $existing->encrypted,
            $locked,
            $existing->version + 1,
            ($this->clock)(),
            $definition?->group(),
            $definition?->category,
            $definition instanceof Definition && $definition->public,
        );

        $layer->driver->put($store->name, $record);

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
