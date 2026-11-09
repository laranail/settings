<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

/**
 * Read and write settings. `store()`, `for()` and `locale()` return a new handle; the shared
 * instance never changes, so a scoped handle cannot leak into another request.
 */
interface Settings
{
    /** A handle on another store. */
    public function store(string $name): static;

    /** A handle for an owner; without an explicit store it routes to the store that accepts the owner's alias. */
    public function for(Settable|OwnerRef $owner): static;

    /** A handle reading and writing one locale. */
    public function locale(string $locale): static;

    /** The value, or `$default` when nothing is stored and the definition has no default. */
    public function get(string $key, mixed $default = null): mixed;

    /** Presence-aware read: found (value possibly null) or missing. */
    public function lookup(string $key): Lookup;

    public function has(string $key): bool;

    /** @throws TypeMismatch */
    public function string(string $key): string;

    /** @throws TypeMismatch */
    public function integer(string $key): int;

    /** @throws TypeMismatch */
    public function float(string $key): float;

    /** @throws TypeMismatch */
    public function boolean(string $key): bool;

    /**
     * @return array<array-key, mixed>
     *
     * @throws TypeMismatch
     */
    public function array(string $key): array;

    /** How a read resolves: every step tried, and the answer. */
    public function explain(string $key): Explanation;

    /**
     * @throws UnknownSetting
     * @throws OwnerNotAllowed
     * @throws LockedSetting
     * @throws InvalidSettingValue
     * @throws StoreNotWritable
     */
    public function set(string $key, mixed $value): void;

    /** Remove this handle's own value, so reads fall through again. */
    public function forget(string $key): void;

    public function lock(string $key): void;

    public function unlock(string $key): void;
}
