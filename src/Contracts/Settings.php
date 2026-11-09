<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Audit\AuditQuery;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Categories\CategoryManager;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\PromotionLocked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Configuration\RuntimeConfiguration;

/**
 * Read and write settings. `store()`, `for()`, `locale()`, `as()`, `asSystem()` and `because()`
 * return a new handle; the shared instance never changes, so a scoped handle cannot leak into
 * another request.
 *
 * A handle with an actor checks every operation, reads included, through the configured
 * authorizer. A handle without one runs as trusted code and is audited as `system`, unless
 * `authorization.require_actor` is on.
 */
interface Settings
{
    /** A handle on another store. */
    public function store(string $name): static;

    /** A handle for an owner; without an explicit store it routes to the store that accepts the owner's alias. */
    public function for(Settable|OwnerRef $owner): static;

    /** A handle reading and writing one locale. */
    public function locale(string $locale): static;

    /**
     * A handle acting as this user or actor: every operation is authorized and audited.
     */
    public function as(Authenticatable|Actor $actor): static;

    /** A handle acting as trusted system code, explicitly; allowed even when an actor is required. */
    public function asSystem(?string $reason = null): static;

    /**
     * A handle acting as whoever the configured actor resolver finds for the request.
     *
     * @throws ActorRequired when nobody is authenticated
     */
    public function asCurrentActor(?Request $request = null): static;

    /** A handle whose changes carry this reason in the audit trail. */
    public function because(string $reason): static;

    /** The actor this handle acts as, or null for trusted code. */
    public function actor(): ?Actor;

    /** Configuration changed in code for the current request only. */
    public function configuration(): RuntimeConfiguration;

    /**
     * Apply configuration changes for the duration of `$run` only, then restore them.
     *
     * @template TResult
     *
     * @param callable(RuntimeConfiguration): mixed $change
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    public function withConfiguration(callable $change, callable $run): mixed;

    /** The value, or `$default` when nothing is stored and the definition has no default. */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Presence-aware read: found (value possibly null) or missing.
     *
     * @throws AccessDenied when the actor may not view the key (or, for a sensitive key, its value)
     */
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

    /** How a read resolves: every step tried, and the answer, redacted when the actor may not view it. */
    public function explain(string $key): Explanation;

    /**
     * @throws UnknownSetting
     * @throws OwnerNotAllowed
     * @throws LockedSetting
     * @throws InvalidSettingValue
     * @throws StoreNotWritable
     * @throws AccessDenied
     * @throws ActorRequired
     * @throws VerificationRequired
     * @throws ApprovalUnavailable
     */
    public function set(string $key, mixed $value): void;

    /** Remove this handle's own value, so reads fall through again. */
    public function forget(string $key): void;

    public function lock(string $key): void;

    public function unlock(string $key): void;

    /**
     * Remove every value this handle's owner holds in its store (an owner being deleted).
     *
     * @throws AccessDenied
     * @throws StoreNotWritable
     */
    public function flush(): void;

    /**
     * Load the settings of many owners now, one read per store, so reading each of them later in
     * the request costs no further query (eager loading, G-9). Includes the global scope and the
     * owners' parents.
     *
     * @param iterable<Settable|OwnerRef> $owners
     */
    public function preload(iterable $owners): void;

    /** The categories of this handle's store, managed as this handle's actor. */
    public function categories(): CategoryManager;

    /**
     * Copy the values written to the store's runtime file before the database existed into its
     * database layer; shipped defaults are never copied. Idempotent.
     *
     * @throws AccessDenied
     * @throws PromotionLocked
     * @throws StoreNotWritable when the store has no active database layer
     */
    public function promote(?PromotionRequest $request = null): PromotionReport;

    /**
     * Query the audit trail (§16.8): `->for($owner)`, `->store()`, `->key()`, `->action()`,
     * `->outcome()`, `->since()`, `->until()`, then `->get()`, `->cursor()` or `->paginate()`.
     * Needs `view_audit` when the handle carries an actor. Entries never hold values.
     */
    public function audit(): AuditQuery;
}
