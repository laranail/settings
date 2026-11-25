<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Lookup;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Audit\AuditQuery;
use Simtabi\Laranail\Settings\Data\HistoryDiff;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Approvals\Approvals;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Simtabi\Laranail\Settings\History\HistoryQuery;
use Simtabi\Laranail\Settings\Data\PromotionRequest;
use Simtabi\Laranail\Settings\Exceptions\InvalidKey;
use Simtabi\Laranail\Settings\Resolution\Explanation;
use Simtabi\Laranail\Settings\Data\PromotionSelection;
use Simtabi\Laranail\Settings\Data\Transfer\StoreDiff;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\ActorRequired;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\UnknownSetting;
use Simtabi\Laranail\Settings\Categories\CategoryManager;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Exceptions\OwnerNotAllowed;
use Simtabi\Laranail\Settings\Exceptions\PromotionLocked;
use Simtabi\Laranail\Settings\Exceptions\StoreNotWritable;
use Simtabi\Laranail\Settings\Data\Transfer\ExportDocument;
use Simtabi\Laranail\Settings\Exceptions\HistoryUnavailable;
use Simtabi\Laranail\Settings\Exceptions\ApprovalUnavailable;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Configuration\PromotionSettings;
use Simtabi\Laranail\Settings\Exceptions\VerificationRequired;
use Simtabi\Laranail\Settings\Configuration\PersistedConfigurationEditor;
use Simtabi\Laranail\Settings\Services\Configuration\RuntimeConfiguration;

/**
 * Read and write settings.
 *
 * The scoping methods (`store()`, `for()`, `locale()`, `as()`, `asSystem()`, `because()`) return a
 * new handle, so a scoped handle never leaks into another request. A handle with an actor
 * authorizes every operation; one without runs as trusted code, audited as `system`.
 */
interface Settings
{
    /** A handle on another store. */
    public function store(string $name): static;

    /** A handle for an owner, routed to the store that accepts its alias unless a store was named. */
    public function for(Settable|OwnerRef $owner): static;

    /** A handle reading and writing one locale. */
    public function locale(string $locale): static;

    /** A handle acting as this user or actor; every operation is authorized and audited. */
    public function as(Authenticatable|Actor $actor): static;

    /** A handle acting as trusted system code, allowed even when an actor is required. */
    public function asSystem(?string $reason = null): static;

    /**
     * A handle acting as the actor the configured resolver finds for the request.
     *
     * @throws ActorRequired when nobody is authenticated
     */
    public function asCurrentActor(?Request $request = null): static;

    /** A handle whose changes carry this reason in the audit trail. */
    public function because(string $reason): static;

    /** The actor this handle acts as, or null for trusted code. */
    public function actor(): ?Actor;

    /** Configuration overrides that last for the current request. */
    public function configuration(): RuntimeConfiguration;

    /**
     * Apply configuration changes while `$run` runs, then restore them.
     *
     * @template TResult
     *
     * @param callable(RuntimeConfiguration): mixed $change
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    public function withConfiguration(callable $change, callable $run): mixed;

    /**
     * Override a value in memory for the rest of the request or job: reads of this handle's store,
     * owner and locale answer with it ahead of every stored layer, and owners below a global
     * override see it too unless they hold their own. Nothing is written, cached, audited or
     * versioned, and no ability is checked. The value is validated and cast as a write is.
     *
     * @param string|array<string, mixed> $key one key, or `key => value` pairs
     *
     * @throws InvalidKey when a key is not a valid setting key
     * @throws InvalidSettingValue when a value fails the definition's rules or `values.max_bytes`
     * @throws UnknownSetting when the store is strict and has no definition for a key
     * @throws OwnerNotAllowed when the store does not accept the handle's owner
     */
    public function override(string|array $key, mixed $value = null): void;

    /** Drop this handle's own override of the key; reads fall back to what is stored. */
    public function forgetOverride(string $key): void;

    /** Whether this handle (its store, owner and locale) holds its own override of the key. */
    public function hasOverride(string $key): bool;

    /** Drop every override held for the current request or job, whatever handle set it. */
    public function clearOverrides(): void;

    /**
     * Override values while `$run` runs, then put every override back as it was, also when `$run`
     * throws. Calls nest.
     *
     * @template TResult
     *
     * @param array<string, mixed> $values `key => value` pairs, as {@see override()} takes
     * @param callable(): TResult $run
     *
     * @return TResult
     */
    public function withOverrides(array $values, callable $run): mixed;

    /** The value, or `$default` when nothing is stored and the definition has no default. */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Read a value, telling a stored null apart from a missing key.
     *
     * @throws AccessDenied when the actor may not view the key (or, for a sensitive key, its value)
     * @throws UnknownSetting when the store is strict and has no definition for the key
     */
    public function lookup(string $key): Lookup;

    public function has(string $key): bool;

    /** Whether nothing is stored and the definition has no default. */
    public function missing(string $key): bool;

    /**
     * Several values, keyed as asked: a list of keys, `key => default` pairs, or both. Each key is
     * read as {@see get()} reads it.
     *
     * @param array<array-key, mixed> $keys
     *
     * @return array<string, mixed>
     *
     * @throws InvalidKey when a list entry is not a string
     * @throws AccessDenied
     */
    public function many(array $keys): array;

    /**
     * Every value this handle can see, in key order. Keys the actor may not read are left out, not
     * masked. `$prefix` keeps that key and the keys below it.
     *
     * @return array<string, mixed>
     */
    public function all(?string $prefix = null): array;

    /**
     * The definitions of this handle's store, in key order. Needs `view`.
     *
     * @return list<Definition>
     *
     * @throws AccessDenied
     */
    public function definitions(): array;

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

    /** Every step a read tries and the answer, redacted when the actor may not view it. */
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

    /** Remove this handle's own value, so reads fall through to the next layer. */
    public function forget(string $key): void;

    /**
     * Write several values with {@see set()}, in order. Not atomic: the first refusal stops the
     * rest, and earlier writes stay.
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): void;

    /**
     * Remove several of this handle's own values with {@see forget()}, in order.
     *
     * @param iterable<string> $keys
     */
    public function forgetMany(iterable $keys): void;

    /**
     * The stored value, or `$value()` written with {@see set()} and read back when nothing is
     * stored. When the write opens a change request instead, the computed value is returned.
     *
     * @param callable(): mixed $value
     */
    public function remember(string $key, callable $value): mixed;

    public function lock(string $key): void;

    public function unlock(string $key): void;

    /**
     * Remove every value this handle's owner holds in its store.
     *
     * @throws AccessDenied
     * @throws StoreNotWritable
     */
    public function flush(): void;

    /**
     * Clear the cached values of this handle's owner, or of the whole store when it has no owner.
     * Stored values are untouched. Needs `manage_stores`.
     *
     * @throws AccessDenied
     */
    public function clearCache(): void;

    /**
     * Load this handle's scope into the cache and return how many records were cached (0 when
     * the store is not cached). Needs `manage_stores`.
     *
     * @throws AccessDenied
     */
    public function warmCache(): int;

    /**
     * Eager-load the settings of many owners, their parents and the global scope, one read per
     * store.
     *
     * @param iterable<Settable|OwnerRef> $owners
     */
    public function preload(iterable $owners): void;

    /** The categories of this handle's store, managed as this handle's actor. */
    public function categories(): CategoryManager;

    /**
     * Copy values from the store's runtime file into its database layer; shipped defaults are
     * never copied. Idempotent.
     *
     * @throws AccessDenied
     * @throws PromotionLocked
     * @throws StoreNotWritable when the store has no active database layer
     */
    public function promote(?PromotionRequest $request = null): PromotionReport;

    /**
     * Promote now if the store's database is ready, and otherwise set the store to promote
     * automatically for the rest of this request, job or command, once it is. Returns the report,
     * or null when the promotion is deferred (or another process holds the promotion lock).
     * `$only` picks the values to copy, as `promote.include` and `promote.owners` do; null uses the
     * store's configuration.
     *
     * @throws AccessDenied
     * @throws StoreNotWritable when the store has no database layer at all
     */
    public function promoteWhenReady(?PromotionSelection $only = null): ?PromotionReport;

    /**
     * Fluent setters for this handle's store's promotion, for the current request or job:
     * `auto()`, `manual()`, `only(...)`, `everything()`, `forOwners(...)`, `forAllOwners()`. Each
     * wins over the same option in configuration.
     */
    public function promotion(): PromotionSettings;

    /**
     * Query the audit trail. Needs `view_audit`. Entries never hold values.
     */
    public function audit(): AuditQuery;

    /**
     * Query the value history of this handle's store. Needs `view_history`. Payloads stay as
     * stored, and are removed for an actor who may not view sensitive values.
     */
    public function history(): HistoryQuery;

    /**
     * Write the value a setting held at `$toVersion` as a new version, and return that version.
     * Needs `rollback`, and step-up verification by default.
     *
     * @throws HistoryUnavailable when the store keeps no history or has no value at that version
     * @throws AccessDenied
     * @throws VerificationRequired
     * @throws LockedSetting
     */
    public function rollback(string $key, int $toVersion): int;

    /**
     * Open a change request for this value instead of writing it. Needs `update`.
     *
     * @throws ApprovalUnavailable when the store cannot hold change requests
     * @throws InvalidSettingValue
     */
    public function propose(string $key, mixed $value): ChangeRequest;

    /**
     * The change requests of this handle's store, decided as this handle's actor.
     */
    public function approvals(): Approvals;

    /**
     * This handle's store as a portable document. Encrypted values are left out unless the mode
     * asks for their ciphertext or, with `view_sensitive`, their plaintext. Needs `export`.
     *
     * @throws AccessDenied
     */
    public function export(ExportMode $mode = ExportMode::Redacted): ExportDocument;

    /**
     * Write a document into this handle's store in one transaction. Locks and system categories
     * in the document are ignored; the console's `--trusted` applies them. Needs `import`.
     *
     * @throws AccessDenied
     * @throws VerificationRequired
     */
    public function import(ExportDocument $document): ImportReport;

    /** What {@see import()} would do, without writing. Needs `import`. */
    public function previewImport(ExportDocument $document): ImportReport;

    /** How this handle's store differs from a document; no values. Needs `export`. */
    public function compare(ExportDocument $document): StoreDiff;

    /**
     * Edit the stores, layers and definitions kept in the database, as this handle's actor.
     * Requires `configuration.persisted`.
     */
    public function persistedConfiguration(): PersistedConfigurationEditor;

    /**
     * Compare a setting at two versions. Needs `view_history`; sensitive values are shown only
     * with `view_sensitive`, otherwise only whether they changed.
     *
     * @throws HistoryUnavailable when the store keeps no history or lacks either version
     */
    public function historyDiff(string $key, int $from, int $to): HistoryDiff;
}
