<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Actions\MigrateSettings;
use Simtabi\Laranail\Settings\Data\SettingsMigrationStep;

/**
 * What a settings migration (G-17) calls: rename, move, delete and add keys, in the default store
 * unless a store is named. Each step runs through {@see MigrateSettings} as the system actor.
 */
final readonly class SettingsMigrator
{
    public function __construct(
        private MigrateSettings $migrate,
        private StoreName $defaultStore,
        private ?Actor $actor = null,
    ) {}

    /** Rename a key in a store, for every owner and locale; returns how many values moved. */
    public function rename(string $from, string $to, ?string $store = null): int
    {
        $name = $this->store($store);

        return $this->migrate->handle(new SettingsMigrationStep(SettingsMigrationStep::RENAME, $name, new Key($from), $name, new Key($to)), $this->actor);
    }

    /** Move a key from one store to another (optionally under a new key). */
    public function move(string $key, string $fromStore, string $toStore, ?string $toKey = null): int
    {
        return $this->migrate->handle(new SettingsMigrationStep(SettingsMigrationStep::RENAME, new StoreName($fromStore), new Key($key), new StoreName($toStore), new Key($toKey ?? $key)), $this->actor);
    }

    /** Remove a key everywhere: every owner and locale. */
    public function delete(string $key, ?string $store = null): int
    {
        return $this->migrate->handle(new SettingsMigrationStep(SettingsMigrationStep::DELETE, $this->store($store), new Key($key)), $this->actor);
    }

    /** Store a global value, unless the key already has one. */
    public function add(string $key, mixed $value, ?string $store = null): int
    {
        return $this->migrate->handle(new SettingsMigrationStep(SettingsMigrationStep::ADD, $this->store($store), new Key($key), value: $value), $this->actor);
    }

    private function store(?string $store): StoreName
    {
        return $store === null ? $this->defaultStore : new StoreName($store);
    }
}
