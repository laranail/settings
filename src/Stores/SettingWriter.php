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

    public function set(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition, mixed $value): void
    {
        $this->guard($store, $key, $locale, $owner, $definition);

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
            locked: false,
            version: $version,
            writtenAt: ($this->clock)(),
        );

        $this->events->dispatch(new SettingSaving($store->name->value, $scope->value, $key->value, $locale->value, $version));
        $layer->driver->put($store->name, $record);
        $this->events->dispatch(new SettingSaved($store->name->value, $scope->value, $key->value, $locale->value, $version));
    }

    public function forget(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition): void
    {
        $this->guard($store, $key, $locale, $owner, $definition);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale);

        if (! $existing instanceof Record) {
            return;
        }

        $this->events->dispatch(new SettingForgetting($store->name->value, $scope->value, $key->value, $locale->value, $existing->version));
        $layer->driver->forget($store->name, $scope, $key, $locale);
        $this->events->dispatch(new SettingForgotten($store->name->value, $scope->value, $key->value, $locale->value, $existing->version));
    }

    /** Lock one row against writes; a lock with no value yet is kept as a value-less row. */
    public function lock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner): void
    {
        $this->storeLock($store, $key, $locale, $owner, locked: true);
    }

    public function unlock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner): void
    {
        $this->storeLock($store, $key, $locale, $owner, locked: false);
    }

    private function storeLock(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, bool $locked): void
    {
        $this->ownerAllowed($store, $owner, null);

        $layer = $store->writableLayer();
        $scope = $this->scope($owner);
        $existing = $layer->driver->load($store->name, [$scope])->find($scope, $key, $locale)
            ?? new Record($scope, $key, $locale, null, version: 0);

        $layer->driver->put($store->name, new Record(
            $existing->scope,
            $existing->key,
            $existing->locale,
            $existing->payload,
            $existing->type,
            $existing->encrypted,
            $locked,
            $existing->version + 1,
            ($this->clock)(),
        ));
    }

    private function guard(Store $store, Key $key, Locale $locale, ?OwnerRef $owner, ?Definition $definition): void
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

        if ($store->writableLayer()->driver->load($store->name, [$scope])->find($scope, $key, $locale)?->locked === true) {
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
