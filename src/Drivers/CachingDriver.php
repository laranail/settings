<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Drivers;

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Simtabi\Laranail\Settings\Contracts\DatabaseBacked;
use Simtabi\Laranail\Settings\Services\Cache\SettingsCache;

/**
 * A database layer behind the settings cache (design §7.3, §7.5): reads come from cached per-scope
 * bags, and every write through it replaces the written scope's generation token once the write
 * has committed.
 *
 * The cache is bypassed, read and write, whenever it could disagree with the database:
 *
 *  - while the layer is inactive (bootstrap mode skipping it), so an empty pre-install read is
 *    never cached and served after the install;
 *  - while a transaction is open on the layer's connection, so a write's own transaction reads
 *    its uncommitted rows and nothing uncommitted (or later rolled back) ever reaches the cache.
 *
 * Writes that reach the database without passing through here (flushing a scope, promotion) ask
 * the store to forget the affected bags; see {@see forgetScope()} and {@see forgetStore()}.
 */
final readonly class CachingDriver implements DatabaseBacked
{
    public function __construct(
        private DatabaseBacked $inner,
        private SettingsCache $cache,
    ) {}

    /** The layer this one caches, for callers that must read the database itself. */
    public function uncached(): DatabaseBacked
    {
        return $this->inner;
    }

    public function database(): DatabaseDriver
    {
        return $this->inner->database();
    }

    public function isActive(): bool
    {
        return $this->inner->isActive();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function load(StoreName $store, array $scopes): RecordSet
    {
        if (! $this->inner->isActive() || $this->inner->database()->connection()->transactionLevel() > 0) {
            return $this->inner->load($store, $scopes);
        }

        return $this->cache->load($this->target($store), $scopes, fn (array $missing): RecordSet => $this->inner->load($store, $missing));
    }

    public function put(StoreName $store, Record $record): void
    {
        $this->inner->put($store, $record);
        $this->forgetScope($store, $record->scope);
    }

    public function forget(StoreName $store, Scope $scope, Key $key, Locale $locale): void
    {
        $this->inner->forget($store, $scope, $key, $locale);
        $this->forgetScope($store, $scope);
    }

    /** Invalidate one scope's bag, once the surrounding transaction (if any) commits. */
    public function forgetScope(StoreName $store, Scope $scope): void
    {
        $this->afterCommit(fn () => $this->cache->forgetScope($this->target($store), $scope));
    }

    /** Invalidate every bag of the store, once the surrounding transaction (if any) commits. */
    public function forgetStore(StoreName $store): void
    {
        $this->afterCommit(fn () => $this->cache->forgetStore($this->target($store)));
    }

    /**
     * Run an invalidation when the layer's connection commits: straight away outside a
     * transaction, never for a transaction that rolls back (nothing changed).
     *
     * @param callable(): void $invalidate
     */
    private function afterCommit(callable $invalidate): void
    {
        $connection = $this->inner->database()->connection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($invalidate);

            return;
        }

        $invalidate();
    }

    private function target(StoreName $store): CacheTarget
    {
        $database = $this->inner->database();

        return new CacheTarget($database->connectionName(), (string) $database->connection()->getDatabaseName(), $store, $database->tableFor($store));
    }
}
