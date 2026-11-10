<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Services\Cache;

use Closure;
use Throwable;
use Illuminate\Support\Str;
use Illuminate\Cache\Repository;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Simtabi\Laranail\Settings\Support\BagCodec;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;

/**
 * The settings cache (design §7.5): raw per-scope bags, never resolved values.
 *
 * A bag holds every row (every locale) one scope has in one store's table, so a write to `team:7`
 * invalidates exactly one entry and every owner inheriting from team 7 sees it on the next read
 * (G-32). Its key hashes connection, database, tenant, store, table and scope as a
 * length-prefixed tuple (R-63: `scope` contains `:`), so no two tenants or databases share an
 * entry (G-37).
 *
 * Invalidation replaces a **generation token** (a fresh ULID) rather than deleting the entry. The
 * bag key carries three of them: the scope's, the store's and a global epoch, so one scope, one
 * store or everything can be reset with one cache write. A stale-while-revalidate refresh
 * computed before a write lands under the old token, where nothing reads any more, so it can
 * never restore a value after its invalidation (R-23). An evicted token yields a new random one
 * and a miss, where a counter would restart and could revive an old entry (R-41).
 *
 * Reads go through `Cache::flexible()` once an entry is past its fresh window: the stale bag is
 * answered and a refresh is deferred. A scope with no rows is cached as an empty bag. Bags and
 * tokens read in a request are held in memory for the rest of it (this service is bound
 * `scoped`, so Octane and queue workers start each request and job empty).
 *
 * A failing cache never fails a read: the bag is read from the database and the failure is
 * reported through `$onFailure`. A failing invalidation is reported the same way.
 */
final class SettingsCache
{
    /** @var array<string, string> token key => token, read or written in this request */
    private array $tokens = [];

    /** @var array<string, RecordSet> bag key => records, read in this request */
    private array $bags = [];

    /**
     * @param int $ttl seconds a bag is fresh
     * @param int $stale seconds after that a stale bag is still answered while it is refreshed
     * @param (Closure(): ?string)|null $tenant the current tenant, null outside any tenant
     * @param (Closure(string, Throwable): void)|null $onFailure told (operation, exception) when the cache fails
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'laranail-settings',
        private readonly int $ttl = 3600,
        private readonly int $stale = 300,
        private readonly ?Closure $tenant = null,
        private readonly ?Closure $onFailure = null,
        private readonly BagCodec $codec = new BagCodec,
    ) {
        if ($prefix === '' || $ttl < 1 || $stale < 0) {
            throw new InvalidConfiguration('laranail.settings.cache: `prefix` must be a non-empty string, `ttl` at least 1 and `stale` at least 0 (seconds).');
        }
    }

    /**
     * These scopes' records: from this request's memory, then the cache, and the rest from
     * `$load` in **one** call, whose bags are then cached (an empty one included).
     *
     * @param list<Scope> $scopes
     * @param Closure(list<Scope>): RecordSet $load reads scopes from the store itself
     */
    public function load(CacheTarget $target, array $scopes, Closure $load): RecordSet
    {
        $scopes = $this->unique($scopes);

        if ($scopes === []) {
            return new RecordSet;
        }

        try {
            [$keys, $found] = $this->lookup($target, $scopes, $load);
        } catch (Throwable $exception) {
            $this->failed('read', $exception);

            return $load($scopes);
        }

        $missing = array_values(array_filter($scopes, static fn (Scope $scope): bool => ! isset($found[$scope->value])));

        if ($missing !== []) {
            $loaded = $load($missing);

            foreach ($missing as $scope) {
                $found[$scope->value] = $this->only($loaded, $scope);
            }

            $this->put(array_map(static fn (Scope $scope): string => $keys[$scope->value], $missing), array_map(static fn (Scope $scope): RecordSet => $found[$scope->value], $missing));
        }

        return new RecordSet(array_merge(...array_values(array_map(static fn (RecordSet $records): array => $records->all(), $found))));
    }

    /** Invalidate one scope's bag in one store, for the current tenant. */
    public function forgetScope(CacheTarget $target, Scope $scope): void
    {
        $this->rotate('scope', $this->scopeTokenKey($target, $this->currentTenant(), $scope), $this->lifetime());
    }

    /** Invalidate every bag of one store, for every tenant. */
    public function forgetStore(CacheTarget $target): void
    {
        $this->rotate('store', $this->storeTokenKey($target), null);
    }

    /** Invalidate every bag this cache holds, for every store and tenant. */
    public function forgetAll(): void
    {
        $this->rotate('all', $this->epochKey(), null);
    }

    /** The key one scope's bag is cached under right now (tokens included). */
    public function bagKey(CacheTarget $target, Scope $scope): string
    {
        $tenant = $this->currentTenant();

        return $this->keyOf($target, $tenant, $scope, $this->tokensFor($target, $tenant, [$scope])[$scope->value]);
    }

    /**
     * The bag key of every scope, and the bags already cached (fresh, or stale and being
     * refreshed), by scope.
     *
     * @param non-empty-list<Scope> $scopes
     * @param Closure(list<Scope>): RecordSet $load
     *
     * @return array{array<string, string>, array<string, RecordSet>}
     */
    private function lookup(CacheTarget $target, array $scopes, Closure $load): array
    {
        $tenant = $this->currentTenant();
        $tokens = $this->tokensFor($target, $tenant, $scopes);
        $keys = [];
        $found = [];
        $pending = [];

        foreach ($scopes as $scope) {
            $key = $keys[$scope->value] = $this->keyOf($target, $tenant, $scope, $tokens[$scope->value]);

            if (isset($this->bags[$key])) {
                $found[$scope->value] = $this->bags[$key];
            } else {
                $pending[$scope->value] = $scope;
            }
        }

        if ($pending === []) {
            return [$keys, $found];
        }

        $entries = $this->cache->many(array_merge(...array_values(array_map(
            static fn (Scope $scope): array => [$keys[$scope->value], Repository::FLEXIBLE_CREATED_KEY_PREFIX . $keys[$scope->value]],
            $pending,
        ))));

        foreach ($pending as $value => $scope) {
            $key = $keys[$value];
            $created = $entries[Repository::FLEXIBLE_CREATED_KEY_PREFIX . $key] ?? null;
            $entry = $entries[$key] ?? null;

            if (! is_int($created) || ! $this->codec->decode($entry) instanceof RecordSet) {
                continue;
            }

            // Past its fresh window: flexible() answers the stale bag and defers a refresh, which
            // lands under this key's tokens (so never over a newer write).
            if ($created + $this->ttl <= now()->getTimestamp()) {
                $entry = $this->cache->flexible($key, [$this->ttl, $this->lifetime()], fn (): array => $this->codec->encode($this->only($load([$scope]), $scope)));
            }

            $records = $this->codec->decode($entry);

            if ($records instanceof RecordSet) {
                $found[$value] = $this->bags[$key] = $records;
            }
        }

        return [$keys, $found];
    }

    /**
     * Cache freshly loaded bags in the shape flexible() reads (entry plus its creation time).
     *
     * @param list<string> $keys
     * @param list<RecordSet> $bags
     */
    private function put(array $keys, array $bags): void
    {
        $entries = [];
        $now = now()->getTimestamp();

        foreach ($keys as $index => $key) {
            $this->bags[$key] = $bags[$index];
            $entries[$key] = $this->codec->encode($bags[$index]);
            $entries[Repository::FLEXIBLE_CREATED_KEY_PREFIX . $key] = $now;
        }

        try {
            $this->cache->putMany($entries, $this->lifetime());
        } catch (Throwable $exception) {
            $this->failed('write', $exception);
        }
    }

    /**
     * The (epoch, store, scope) tokens of each scope, as one generation string per scope. Missing
     * tokens are created; every token is read in one cache call.
     *
     * @param non-empty-list<Scope> $scopes
     *
     * @return array<string, string> scope => generation
     */
    private function tokensFor(CacheTarget $target, string $tenant, array $scopes): array
    {
        $epoch = $this->epochKey();
        $store = $this->storeTokenKey($target);
        $scopeKeys = [];

        foreach ($scopes as $scope) {
            $scopeKeys[$scope->value] = $this->scopeTokenKey($target, $tenant, $scope);
        }

        $wanted = array_values(array_filter([$epoch, $store, ...array_values($scopeKeys)], fn (string $key): bool => ! isset($this->tokens[$key])));

        if ($wanted !== []) {
            $read = $this->cache->many($wanted);

            foreach ($wanted as $key) {
                $token = $read[$key] ?? null;
                $this->tokens[$key] = is_string($token) && $token !== '' ? $token : $this->create($key, $key === $epoch || $key === $store ? null : $this->lifetime());
            }
        }

        $generations = [];

        foreach ($scopeKeys as $value => $key) {
            $generations[$value] = $this->tokens[$epoch] . '.' . $this->tokens[$store] . '.' . $this->tokens[$key];
        }

        return $generations;
    }

    /** A new token for a key that had none; a concurrent reader that created one first wins. */
    private function create(string $key, ?int $seconds): string
    {
        $token = (string) Str::ulid();

        if ($this->cache->add($key, $token, $seconds)) {
            return $token;
        }

        $winner = $this->cache->get($key);

        return is_string($winner) && $winner !== '' ? $winner : $token;
    }

    private function rotate(string $operation, string $key, ?int $seconds): void
    {
        $token = (string) Str::ulid();
        $this->tokens[$key] = $token;

        try {
            $seconds === null ? $this->cache->forever($key, $token) : $this->cache->put($key, $token, $seconds);
        } catch (Throwable $exception) {
            $this->failed('invalidate ' . $operation, $exception);
        }
    }

    private function keyOf(CacheTarget $target, string $tenant, Scope $scope, string $generation): string
    {
        return sprintf('%s:%s:%s', $this->prefix, $this->hash([...$target->parts(), $tenant, $scope->value]), hash('sha1', $generation));
    }

    private function epochKey(): string
    {
        return $this->prefix . ':epoch';
    }

    /** Per store and database, not per tenant: a store reset reaches every tenant's bags. */
    private function storeTokenKey(CacheTarget $target): string
    {
        return $this->prefix . ':store:' . $this->hash($target->parts());
    }

    private function scopeTokenKey(CacheTarget $target, string $tenant, Scope $scope): string
    {
        return $this->prefix . ':scope:' . $this->hash([...$target->parts(), $tenant, $scope->value]);
    }

    /**
     * A length-prefixed tuple, hashed: `a` + `b:c` and `a:b` + `c` stay different (R-63).
     *
     * @param list<string> $parts
     */
    private function hash(array $parts): string
    {
        return hash('sha1', implode('', array_map(static fn (string $part): string => strlen($part) . ':' . $part, $parts)));
    }

    private function currentTenant(): string
    {
        return ($this->tenant instanceof Closure ? ($this->tenant)() : null) ?? '';
    }

    /** Seconds a bag (and a scope token) is kept: fresh plus stale. */
    private function lifetime(): int
    {
        return $this->ttl + $this->stale;
    }

    private function only(RecordSet $records, Scope $scope): RecordSet
    {
        return new RecordSet(array_filter($records->all(), static fn (Record $record): bool => $record->scope->equals($scope)));
    }

    /**
     * @param list<Scope> $scopes
     *
     * @return list<Scope>
     */
    private function unique(array $scopes): array
    {
        $unique = [];

        foreach ($scopes as $scope) {
            $unique[$scope->value] = $scope;
        }

        return array_values($unique);
    }

    private function failed(string $operation, Throwable $exception): void
    {
        if ($this->onFailure instanceof Closure) {
            ($this->onFailure)($operation, $exception);
        }
    }
}
