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
use Simtabi\Laranail\Settings\Events\SettingForgotten;
use Simtabi\Laranail\Settings\Events\SettingForgetting;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Every write path: set, forget, lock, unlock. Checks run before anything is stored, in this
 * order: definition (strict stores) → owner allowed → lock and precedence → locale → value
 * pipeline → the first writable layer, with the version continued from the existing record.
 */
final readonly class SettingWriter
{
    /** @param Closure(): CarbonImmutable $clock */
    public function __construct(
        private ValuePipeline $pipeline,
        private Dispatcher $events,
        private Closure $clock,
    ) {}

    /** Store a value and return the stored record. A locked row refuses it. */
    public function set(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): Record
    {
        return $this->write($store, $key, $locale, $owner, $definition, $value, overrideLock: false);
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

    /** Lock one row against writes; a lock with no value yet is kept as a value-less row. */
    public function lock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: true);
    }

    public function unlock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner): Record
    {
        return $this->storeLock($store, $key, $locale, $owner, locked: false);
    }

    private function write(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value, bool $overrideLock): Record
    {
        $this->guard($store, $key, $locale, $owner, $definition, $overrideLock);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale);
        $version = ($existing->version ?? 0) + 1;

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
        );

        $this->events->dispatch(new SettingSaving($store->name->value, $scope->value, $key->value, $locale->value, $version));
        $layer->driver->put($store->name, $record);
        $this->events->dispatch(new SettingSaved($store->name->value, $scope->value, $key->value, $locale->value, $version));

        return $record;
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

    private function storeLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, bool $locked): Record
    {
        $this->ownerAllowed($store, $owner, null);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale)
            ?? new Record($scope, $key, $locale, null, version: 0);

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
        return $owner?->scope() ?? Scope::global();
    }
}
