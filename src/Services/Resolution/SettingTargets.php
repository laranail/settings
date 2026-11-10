<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Resolution;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Data\SettingAddress;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;

/**
 * Turns a {@see SettingAddress} (how a caller outside PHP names a setting) into what the actions
 * take: the resolved store, the write target with its definition, a handle for reads, and the
 * version of the address's own row. The definition is found as a settings handle finds it.
 */
final readonly class SettingTargets
{
    public function __construct(
        private StoreRegistry $stores,
        private Definitions $definitions,
    ) {}

    public function store(string $name): Store
    {
        return $this->stores->get(new StoreName($name));
    }

    /** @throws OwnerNotAllowed when the store does not accept the owner */
    public function target(SettingAddress $address, ?string $reason = null): SettingTarget
    {
        $store = $this->store($address->store);
        $owner = $address->ownerRef();
        $key = new Key($address->key);

        if ($owner instanceof OwnerRef && ! $store->accepts($owner->alias)) {
            throw new OwnerNotAllowed(sprintf('Store [%s] does not accept owner alias [%s].', $store->name, $owner->alias));
        }

        return new SettingTarget($store, $key, new Locale($address->locale), $owner, $this->definitions->find($store, $key), $reason);
    }

    /** The settings handle reading this address's store, owner and locale as the actor. */
    public function handle(Settings $settings, SettingAddress $address, ?Actor $actor): Settings
    {
        $handle = $settings->store($address->store);
        $handle = $address->owner === null ? $handle : $handle->for($address->owner);
        $handle = $address->locale === '' ? $handle : $handle->locale($address->locale);

        return $actor instanceof Actor ? $handle->as($actor) : $handle;
    }

    /** The version of the address's own row in the store's writable layer (0 when it has none). */
    public function version(SettingAddress $address): int
    {
        $store = $this->store($address->store);
        $scope = Scope::of($address->ownerRef());

        return $store->writableLayer()->driver->load($store->name, [$scope])->find($scope, new Key($address->key), new Locale($address->locale))->version ?? 0;
    }
}
